<?php

namespace App\Services\Cash;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash register shifts.
 *
 *  open  — one open shift per terminal, per drawer and per cashier
 *  cash in / cash out / payout — append-only movements
 *  close — "blind" count: the cashier counts first, then sees expected vs counted
 *
 * Expected cash = opening float + cash received − cash paid out (refunds)
 *               + cash in − cash out − payouts.
 * Every write is idempotent on the client-generated UUID, so a retried
 * request (flaky shop Wi-Fi) never opens two shifts or records cash twice.
 */
class CashSessionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function open(Branch $branch, Terminal $terminal, ?int $cashRegisterId, string $openingFloat, string $clientUuid, User $actor): CashRegisterSession
    {
        if ($existing = CashRegisterSession::query()->where('client_uuid', $clientUuid)->first()) {
            return $this->sameActor($existing, $actor);
        }

        if ($terminal->branch_id !== $branch->getKey() || ! $terminal->isActive()) {
            throw ValidationException::withMessages(['terminal_id' => 'Choose an active terminal of this branch.']);
        }
        $float = Money::of($openingFloat);
        if ($float->isNegative()) {
            throw ValidationException::withMessages(['opening_float' => 'The opening float cannot be negative.']);
        }

        try {
            return DB::transaction(function () use ($branch, $terminal, $cashRegisterId, $float, $clientUuid, $actor) {
                $mine = CashRegisterSession::query()->where('user_id', $actor->getKey())->where('status', 'open')->lockForUpdate()->first();
                if ($mine) {
                    throw new DomainException(
                        "You already have an open shift on {$mine->terminal?->name}. Close it before starting another.",
                        'SESSION_ALREADY_OPEN', 409, ['session_id' => $mine->getKey()],
                    );
                }

                $busy = CashRegisterSession::query()->with('user')->where('terminal_id', $terminal->getKey())->where('status', 'open')->lockForUpdate()->first();
                if ($busy) {
                    throw new DomainException("{$terminal->name} is in use by {$busy->user?->name}.", 'TERMINAL_IN_USE', 409);
                }

                $drawer = $this->drawerFor($branch, $terminal, $cashRegisterId);
                if (CashRegisterSession::query()->where('cash_register_id', $drawer->getKey())->where('status', 'open')->lockForUpdate()->exists()) {
                    throw new DomainException("The drawer \"{$drawer->name}\" is already in use.", 'DRAWER_IN_USE', 409);
                }

                $session = new CashRegisterSession;
                $session->forceFill([
                    'client_uuid' => $clientUuid,
                    'branch_id' => $branch->getKey(),
                    'terminal_id' => $terminal->getKey(),
                    'cash_register_id' => $drawer->getKey(),
                    'user_id' => $actor->getKey(),
                    'status' => 'open',
                    'opened_at' => now(),
                    'opening_float' => (string) $float,
                    'origin' => 'online',
                ])->save();

                $this->audit->log('cash_session.opened', $session, [], [
                    'terminal' => $terminal->code, 'drawer' => $drawer->name, 'opening_float' => (string) $float,
                ]);

                return $session;
            });
        } catch (QueryException $e) {
            // The unique "one open session per terminal" index caught a race.
            if (str_contains($e->getMessage(), 'cash_register_sessions_one_open_per_terminal')) {
                throw new DomainException("{$terminal->name} is already in use by another cashier.", 'TERMINAL_IN_USE', 409);
            }
            throw $e;
        }
    }

    public function recordMovement(CashRegisterSession $session, string $type, string $amount, string $reason, string $clientUuid, User $actor): CashMovement
    {
        if ($existing = CashMovement::query()->where('client_uuid', $clientUuid)->first()) {
            if ($existing->cash_register_session_id !== $session->getKey()) {
                throw new DomainException('This request was already used for another shift.', 'IDEMPOTENCY_CONFLICT', 409);
            }

            return $existing;
        }

        return DB::transaction(function () use ($session, $type, $amount, $reason, $clientUuid, $actor) {
            $session = $this->lockOpen($session);
            $this->assertCanOperate($session, $actor);

            $value = Money::of($amount);
            if (! $value->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
            }
            if ($type !== 'cash_in') {
                $inDrawer = Money::of($this->summary($session)['expected_cash']);
                if ($value->greaterThan($inDrawer)) {
                    throw ValidationException::withMessages(['amount' => "The drawer should only have {$inDrawer->format()}."]);
                }
            }

            $movement = new CashMovement;
            $movement->forceFill([
                'client_uuid' => $clientUuid,
                'cash_register_session_id' => $session->getKey(),
                'type' => $type,
                'amount' => (string) $value,
                'reason' => $reason,
                'user_id' => $actor->getKey(),
                'occurred_at' => now(),
            ])->save();

            $this->audit->log("cash_session.{$type}", $session, [], ['amount' => (string) $value], ['reason' => $reason]);

            return $movement;
        });
    }

    /**
     * Close with the counted cash, from a bill/coin count or a total.
     *
     * @param  array<string, int>|null  $denominations  denomination key => pieces
     */
    public function close(CashRegisterSession $session, ?array $denominations, ?string $countedCash, ?string $notes, User $actor): CashRegisterSession
    {
        return DB::transaction(function () use ($session, $denominations, $countedCash, $notes, $actor) {
            $session = $this->lockOpen($session);
            $this->assertCanOperate($session, $actor, closing: true);

            if ($denominations !== null) {
                $denominations = array_filter($denominations, fn ($pieces) => (int) $pieces > 0);
                $counted = Money::sum(array_map(
                    fn ($key, $pieces) => Money::of(BigDecimal::of(CashRegisterSession::denominationValue((string) $key))->multipliedBy((int) $pieces)),
                    array_keys($denominations), $denominations,
                ));
            } elseif ($countedCash !== null) {
                $counted = Money::of($countedCash);
            } else {
                throw ValidationException::withMessages(['counted_cash' => 'Count the cash in the drawer.']);
            }

            $expected = Money::of($this->summary($session)['expected_cash']);
            $variance = $counted->minus($expected);

            $session->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'expected_cash' => (string) $expected,
                'counted_cash' => (string) $counted,
                'variance' => (string) $variance,
                'denominations' => $denominations ?: null,
                'closing_notes' => $notes,
            ])->save();

            $this->audit->log('cash_session.closed', $session, [], [
                'expected_cash' => (string) $expected, 'counted_cash' => (string) $counted, 'variance' => (string) $variance,
            ], array_filter(['closed_for' => $session->user_id !== $actor->getKey() ? $session->user_id : null]));

            return $session;
        });
    }

    /**
     * Money that went through the shift. Payments are posted by the POS (Phase 5B);
     * refunds paid in cash reduce the drawer.
     *
     * @return array{opening_float: string, cash_sales: string, cash_refunds: string, cash_in: string, cash_out: string,
     *               payouts: string, expected_cash: string, sales_count: int, payments_by_method: list<array{method: string, type: string, amount: string}>}
     */
    public function summary(CashRegisterSession $session): array
    {
        $payments = DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.tenant_id', $session->tenant_id)
            ->where('payments.cash_register_session_id', $session->getKey())
            ->where('payments.status', 'confirmed')
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type', 'payments.direction')
            ->selectRaw('payment_methods.name AS method, payment_methods.type AS type, payments.direction AS direction, SUM(payments.amount) AS amount')
            ->get();

        $movements = CashMovement::query()->where('cash_register_session_id', $session->getKey())
            ->groupBy('type')->selectRaw('type, SUM(amount) AS amount')->pluck('amount', 'type');

        $cashIn = fn (string $direction) => Money::sum($payments->where('type', 'cash')->where('direction', $direction)->pluck('amount')->all());
        $cashSales = $cashIn('in');
        $cashRefunds = $cashIn('out');
        $move = fn (string $type) => Money::of($movements[$type] ?? '0');

        $expected = Money::of($session->opening_float)->plus($cashSales)->minus($cashRefunds)
            ->plus($move('cash_in'))->minus($move('cash_out'))->minus($move('payout'));

        $byMethod = $payments->groupBy('method')->map(fn ($rows, $method) => [
            'method' => $method,
            'type' => $rows->first()->type,
            'amount' => (string) Money::of(Money::sum($rows->where('direction', 'in')->pluck('amount')->all()))
                ->minus(Money::sum($rows->where('direction', 'out')->pluck('amount')->all())),
        ])->values()->all();

        return [
            'opening_float' => (string) Money::of($session->opening_float),
            'cash_sales' => (string) $cashSales,
            'cash_refunds' => (string) $cashRefunds,
            'cash_in' => (string) $move('cash_in'),
            'cash_out' => (string) $move('cash_out'),
            'payouts' => (string) $move('payout'),
            'expected_cash' => (string) $expected,
            'sales_count' => DB::table('sales')->where('tenant_id', $session->tenant_id)
                ->where('cash_register_session_id', $session->getKey())->whereNotIn('status', ['voided'])->count(),
            'payments_by_method' => $byMethod,
        ];
    }

    /**
     * The drawer for a shift: the one chosen, else the terminal's own,
     * else a shared drawer of the branch.
     */
    private function drawerFor(Branch $branch, Terminal $terminal, ?int $cashRegisterId): CashRegister
    {
        $query = CashRegister::query()->where('branch_id', $branch->getKey())->where('is_active', true)
            ->where(fn ($q) => $q->where('terminal_id', $terminal->getKey())->orWhereNull('terminal_id'));

        $drawer = $cashRegisterId !== null
            ? (clone $query)->find($cashRegisterId)
            : (clone $query)->orderByRaw('terminal_id IS NULL')->orderBy('id')->first();

        if (! $drawer) {
            throw ValidationException::withMessages(['cash_register_id' => $cashRegisterId !== null
                ? 'Choose a drawer of this terminal or a shared drawer of the branch.'
                : 'This terminal has no active cash drawer. Add one in Settings → POS terminals.']);
        }

        return $drawer;
    }

    private function lockOpen(CashRegisterSession $session): CashRegisterSession
    {
        $locked = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->getKey());
        if (! $locked->isOpen()) {
            throw new DomainException('This shift is already closed.', 'SESSION_CLOSED', 409);
        }

        return $locked;
    }

    /**
     * The cashier works their own shift; a manager who can see all shifts
     * may also record movements or close a forgotten one.
     */
    private function assertCanOperate(CashRegisterSession $session, User $actor, bool $closing = false): void
    {
        $own = $session->user_id === $actor->getKey();
        $manager = $actor->can('cash.view_all_sessions');
        $permission = $closing ? 'cash.close_session' : 'cash.cash_in_out';

        if (! $actor->can($permission) || (! $own && ! $manager)) {
            throw new DomainException('You cannot do this on another cashier\'s shift.', 'FORBIDDEN', 403);
        }
    }

    private function sameActor(CashRegisterSession $session, User $actor): CashRegisterSession
    {
        if ($session->user_id !== $actor->getKey()) {
            throw new DomainException('This request was already used by someone else.', 'IDEMPOTENCY_CONFLICT', 409);
        }

        return $session;
    }
}
