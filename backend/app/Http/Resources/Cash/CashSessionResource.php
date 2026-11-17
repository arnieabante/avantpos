<?php

namespace App\Http\Resources\Cash;

use App\Http\Resources\ApiResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use Illuminate\Http\Request;

/**
 * A shift. While it is open, the cashier does not see the expected cash
 * (blind count); managers who can view all shifts do.
 *
 * @property CashRegisterSession $resource
 */
class CashSessionResource extends ApiResource
{
    public bool $withDetail = false;

    public function detailed(): static
    {
        $this->withDetail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $manager = (bool) $user?->can('cash.view_all_sessions');
        $own = $user?->getKey() === $s->user_id;
        $showExpected = ! $s->isOpen() || $manager;

        $data = [
            'id' => $s->id,
            'status' => $s->status,
            'branch' => InventoryPresenter::branch($s->branch),
            'terminal' => $s->terminal ? ['id' => $s->terminal->id, 'code' => $s->terminal->code, 'name' => $s->terminal->name] : null,
            'drawer' => $s->cashRegister ? ['id' => $s->cashRegister->id, 'name' => $s->cashRegister->name] : null,
            'cashier' => InventoryPresenter::user($s->user),
            'opened_at' => $s->opened_at?->toIso8601String(),
            'opening_float' => $s->opening_float,
            'closed_at' => $s->closed_at?->toIso8601String(),
            'closed_by' => InventoryPresenter::user($s->closer),
            'counted_cash' => $s->counted_cash,
            'expected_cash' => $showExpected ? $s->expected_cash : null,
            'variance' => $showExpected ? $s->variance : null,
            'closing_notes' => $s->closing_notes,
            'can' => [
                'operate' => $s->isOpen() && ($own || $manager) && (bool) $user?->can('cash.cash_in_out'),
                'close' => $s->isOpen() && ($own || $manager) && (bool) $user?->can('cash.close_session'),
            ],
        ];

        if ($this->withDetail) {
            $summary = app(CashSessionService::class)->summary($s);
            if (! $showExpected) {
                unset($summary['expected_cash']);
            }
            $data['summary'] = $summary;
            $data['denominations'] = $s->denominations;
            $data['movements'] = $s->movements()->with(['user', 'authorizer'])->get()->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => $m->amount,
                'reason' => $m->reason,
                'user' => InventoryPresenter::user($m->user),
                'occurred_at' => $m->occurred_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
