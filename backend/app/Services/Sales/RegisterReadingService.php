<?php

namespace App\Services\Sales;

use App\Exceptions\DomainException;
use App\Models\CashRegisterSession;
use App\Models\RegisterReading;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * X and Z readings of a terminal (BIR).
 *
 *  - X reading: the sales of one shift, any time (does not reset anything).
 *  - Z reading: end of day. Covers every sale and refund on the terminal
 *    since the previous Z reading, needs all shifts on the terminal closed,
 *    and adds the day's net sales to the non-resettable accumulated grand
 *    total. One per terminal per business day.
 *
 * Figures (all from recorded sales / refunds, never recomputed):
 *   gross sales      = Σ gross of every invoice issued (voided ones included)
 *   SC / PWD / other = discounts on those invoices
 *   voids            = Σ total of voided invoices
 *   refunds          = Σ refunds processed on the terminal
 *   net sales        = what valid invoices charged − refunds
 *                      (= gross − discounts − voids − refunds − VAT removed from SC/PWD items)
 *   VAT breakdown    = of the valid invoices, less the refunded share.
 */
class RegisterReadingService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function x(CashRegisterSession $session, User $actor): RegisterReading
    {
        return DB::transaction(function () use ($session, $actor) {
            $figures = $this->figures(
                fn ($q) => $q->where('sales.cash_register_session_id', $session->getKey()),
                fn ($q) => $q->where('refunds.cash_register_session_id', $session->getKey()),
                fn ($q) => $q->where('payments.cash_register_session_id', $session->getKey()),
            );
            $reading = $this->store('X', $session->terminal_id, $session->getKey(), $figures, $actor);
            $this->audit->log('register.x_reading', $reading, meta: ['reading_no' => $reading->reading_no, 'session' => $session->getKey()]);

            return $reading;
        });
    }

    public function z(Terminal $terminal, User $actor): RegisterReading
    {
        return DB::transaction(function () use ($terminal, $actor) {
            // Serialise Z readings of the same terminal.
            Terminal::query()->lockForUpdate()->findOrFail($terminal->getKey());

            if (CashRegisterSession::query()->where('terminal_id', $terminal->getKey())->where('status', 'open')->exists()) {
                throw new DomainException('Close the open shift on this terminal before the Z reading.', 'SHIFT_STILL_OPEN', 409);
            }
            $today = now($this->timezone())->toDateString();
            if (RegisterReading::query()->where('terminal_id', $terminal->getKey())->where('type', 'Z')->whereDate('business_date', $today)->exists()) {
                throw new DomainException('The Z reading for today was already done on this terminal.', 'Z_ALREADY_DONE', 409);
            }

            $last = RegisterReading::query()->where('terminal_id', $terminal->getKey())->where('type', 'Z')->latest('reading_no')->first();
            $since = $last?->generated_at;
            $until = now();
            $window = fn (string $column) => fn ($q) => $q->where($column, '<=', $until)
                ->when($since, fn ($w) => $w->where($column, '>', $since));

            $figures = $this->figures(
                fn ($q) => $window('sales.sold_at')($q->where('sales.terminal_id', $terminal->getKey())),
                fn ($q) => $window('refunds.refunded_at')($q->where('refunds.terminal_id', $terminal->getKey())),
                fn ($q) => $window('payments.paid_at')($q->whereIn('payments.cash_register_session_id', CashRegisterSession::query()->where('terminal_id', $terminal->getKey())->select('id'))),
            );
            $reading = $this->store('Z', $terminal->getKey(), null, $figures, $actor, $last);
            $this->audit->log('register.z_reading', $reading, meta: [
                'reading_no' => $reading->reading_no, 'terminal' => $terminal->code, 'net_sales' => (string) $reading->net_sales,
            ]);

            return $reading;
        });
    }

    /** The terminal's latest Z reading, if any. */
    public function lastZ(Terminal $terminal): ?RegisterReading
    {
        return RegisterReading::query()->where('terminal_id', $terminal->getKey())->where('type', 'Z')->latest('reading_no')->first();
    }

    /**
     * @param  callable(Builder): mixed  $sales
     * @param  callable(Builder): mixed  $refunds
     * @param  callable(Builder): mixed  $payments
     * @return array<string, mixed>
     */
    private function figures(callable $sales, callable $refunds, callable $payments): array
    {
        $tenantId = $this->tenant->requireId();
        $saleQ = fn () => tap(DB::table('sales')->where('sales.tenant_id', $tenantId), $sales);

        $all = $saleQ()->selectRaw('COUNT(*) AS n, MIN(invoice_no) AS first_no, MAX(invoice_no) AS last_no,
            COALESCE(SUM(gross_amount),0) AS gross, COALESCE(SUM(line_discount_total + transaction_discount_total + loyalty_discount),0) AS other')->first();
        $valid = $saleQ()->where('status', '!=', 'voided')->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total,
            COALESCE(SUM(vatable_sales),0) AS vatable, COALESCE(SUM(vat_amount),0) AS vat,
            COALESCE(SUM(vat_exempt_sales),0) AS exempt, COALESCE(SUM(zero_rated_sales),0) AS zero')->first();
        $voids = $saleQ()->where('status', 'voided')->selectRaw('COALESCE(SUM(total_amount),0) AS total')->value('total');

        $statutory = DB::table('sale_discounts')->join('sales', 'sales.id', '=', 'sale_discounts.sale_id')
            ->where('sale_discounts.tenant_id', $tenantId)->whereIn('sale_discounts.type', ['senior', 'pwd'])
            ->tap($sales)->groupBy('sale_discounts.type')->selectRaw('sale_discounts.type AS type, SUM(sale_discounts.amount) AS amount')
            ->pluck('amount', 'type');

        // Refunds split by the tax class of the refunded lines.
        $refundRows = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sale_items', 'sale_items.id', '=', 'refund_items.sale_item_id')
            ->where('refund_items.tenant_id', $tenantId)->tap($refunds)
            ->groupBy('sale_items.tax_class')
            // For Senior/PWD lines the VAT-exempt sale is the price before the discount, so its share is added back.
            ->selectRaw('sale_items.tax_class AS tax_class, SUM(refund_items.amount) AS amount, SUM(refund_items.vat_amount) AS vat,
                SUM(CASE WHEN sale_items.qty > 0 THEN ROUND(sale_items.sc_pwd_discount * refund_items.qty / sale_items.qty, 2) ELSE 0 END) AS sc')
            ->get()->keyBy('tax_class');
        $refundTotal = Money::sum($refundRows->pluck('amount')->all());
        $refundCount = DB::table('refunds')->where('refunds.tenant_id', $tenantId)->tap($refunds)->count();
        $r = fn (string $class, string $field) => Money::of($refundRows[$class]->{$field} ?? '0');

        $payRows = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.tenant_id', $tenantId)->where('payments.status', 'confirmed')->tap($payments)
            ->groupBy('payment_methods.name', 'payment_methods.type', 'payments.direction')
            ->selectRaw('payment_methods.name AS method, payment_methods.type AS type, payments.direction AS direction, SUM(payments.amount) AS amount')
            ->get();
        $breakdown = $payRows->groupBy('method')->map(fn ($rows, $method) => [
            'method' => $method,
            'type' => $rows->first()->type,
            'received' => (string) Money::sum($rows->where('direction', 'in')->pluck('amount')->all()),
            'refunded' => (string) Money::sum($rows->where('direction', 'out')->pluck('amount')->all()),
        ])->values()->all();

        $sc = Money::of($statutory['senior'] ?? '0');
        $pwd = Money::of($statutory['pwd'] ?? '0');
        $other = Money::of($all->other);
        $gross = Money::of($all->gross);
        $voidTotal = Money::of($voids);
        // Net sales = what valid invoices charged, less returns. The difference to
        // gross − discounts − voids − returns is the VAT removed from SC/PWD items.
        $net = Money::of($valid->total)->minus($refundTotal);
        $scPwdVat = $gross->minus($sc)->minus($pwd)->minus($other)->minus($voidTotal)->minus($refundTotal)->minus($net);

        return [
            'beginning_invoice_no' => $all->first_no,
            'ending_invoice_no' => $all->last_no,
            'transaction_count' => (int) $valid->n,
            'gross_sales' => $gross,
            'sc_discount' => $sc,
            'pwd_discount' => $pwd,
            'other_discount' => $other,
            'void_amount' => $voidTotal,
            'refund_amount' => $refundTotal,
            'net_sales' => $net,
            'vatable_sales' => Money::of($valid->vatable)->minus($r('vatable', 'amount')->minus($r('vatable', 'vat'))),
            'vat_amount' => Money::of($valid->vat)->minus($r('vatable', 'vat')),
            'vat_exempt_sales' => Money::of($valid->exempt)->minus($r('vat_exempt', 'amount'))->minus($r('vat_exempt', 'sc'))->minus($r('non_vat', 'amount')),
            'zero_rated_sales' => Money::of($valid->zero)->minus($r('zero_rated', 'amount')),
            'payment_breakdown' => ['methods' => $breakdown, 'void_count' => (int) $all->n - (int) $valid->n, 'refund_count' => $refundCount, 'sc_pwd_vat' => (string) $scPwdVat],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function store(string $type, int $terminalId, ?int $sessionId, array $f, User $actor, ?RegisterReading $lastZ = null): RegisterReading
    {
        $lastZ ??= RegisterReading::query()->where('terminal_id', $terminalId)->where('type', 'Z')->latest('reading_no')->first();
        $before = Money::of($lastZ->accumulated_grand_total_after ?? '0');
        $number = DB::table('register_readings')->where('terminal_id', $terminalId)->where('type', $type)->lockForUpdate()->max('reading_no');

        $reading = new RegisterReading;
        $reading->forceFill([
            'terminal_id' => $terminalId,
            'cash_register_session_id' => $sessionId,
            'type' => $type,
            'reading_no' => ((int) $number) + 1,
            'business_date' => now($this->timezone())->toDateString(),
            'beginning_invoice_no' => $f['beginning_invoice_no'],
            'ending_invoice_no' => $f['ending_invoice_no'],
            'transaction_count' => $f['transaction_count'],
            'payment_breakdown' => $f['payment_breakdown'],
            'accumulated_grand_total_before' => (string) $before,
            'accumulated_grand_total_after' => (string) $before->plus($f['net_sales']),
            'generated_by' => $actor->getKey(),
            'generated_at' => now(),
        ] + array_map(fn ($v) => (string) $v, array_intersect_key($f, array_flip([
            'gross_sales', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'zero_rated_sales', 'sc_discount', 'pwd_discount',
            'other_discount', 'void_amount', 'refund_amount', 'net_sales',
        ]))))->save();

        return $reading;
    }

    private function timezone(): string
    {
        return $this->tenant->tenant()?->timezone ?: 'Asia/Manila';
    }
}
