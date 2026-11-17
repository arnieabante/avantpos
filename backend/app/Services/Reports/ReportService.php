<?php

namespace App\Services\Reports;

use App\Models\SaleDiscount;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business reports. One set of definitions everywhere (all from recorded
 * documents, never recomputed from current prices):
 *
 *   Gross sales      list value of the items sold (VAT-inclusive, before discounts)
 *   Discounts        item + sale + Senior/PWD + points discounts
 *   Less: SC/PWD VAT VAT removed from Senior/PWD items (they are VAT-exempt)
 *   Returns          amounts refunded (by refund date)
 *   Net sales        what customers were charged, less returns (VAT-inclusive)
 *                    = gross − discounts − SC/PWD VAT − returns
 *   VAT              output VAT of net sales (sales VAT − VAT refunded)
 *   Revenue          net sales excluding VAT
 *   Cost of goods    FIFO cost of the items sold, less the cost of items returned to stock
 *   Gross profit     revenue − cost of goods
 *   Cash collected   payments received less refunds paid out, per payment method
 *                    (charges to customer accounts are not collected cash; account
 *                    payments received are)
 *   Expenses         approved and paid expenses (by expense date)
 *
 * Voided sales are excluded everywhere except where counted as voids.
 */
class ReportService
{
    public const STATUTORY = ['senior' => 'Senior Citizen', 'pwd' => 'PWD'];

    public function __construct(private readonly TenantContext $tenant) {}

    /* ================================================================ sales over time */

    public function sales(ReportFilters $f): Report
    {
        $period = $this->periodSql($f, $f->local('sales.sold_at'));
        $sales = $this->validSales($f)
            ->groupByRaw($period)
            ->selectRaw("{$period} AS period, COUNT(*) AS n, SUM(gross_amount) AS gross,
                SUM(line_discount_total + transaction_discount_total + sc_pwd_discount_total + loyalty_discount) AS discounts,
                SUM(total_amount) AS total, SUM(vat_amount) AS vat, SUM(cogs_total) AS cogs")
            ->get()->keyBy('period');

        $rperiod = $this->periodSql($f, $f->local('refunds.refunded_at'));
        $refunds = $this->refunds($f)
            ->groupByRaw($rperiod)
            ->selectRaw("{$rperiod} AS period, SUM(total) AS total, SUM(vat_amount) AS vat, SUM(cogs_reversed) AS cogs")
            ->get()->keyBy('period');

        $rows = [];
        foreach ($this->periods($f, $sales->keys()->merge($refunds->keys())) as $key) {
            $rows[] = ['period' => $key] + $this->salesFigures($sales[$key] ?? null, $refunds[$key] ?? null);
        }

        $totals = ['period' => 'Total'] + $this->sumFigures($rows);

        return new Report('sales', 'Sales', [
            $this->col('period', $this->periodLabel($f->groupBy), 'text'),
            $this->col('transactions', 'Sales', 'int'),
            $this->col('gross_sales', 'Gross sales', 'money'),
            $this->col('discounts', 'Discounts', 'money'),
            $this->col('sc_pwd_vat', 'Less: SC/PWD VAT', 'money'),
            $this->col('returns', 'Returns', 'money'),
            $this->col('net_sales', 'Net sales', 'money'),
            $this->col('vat', 'VAT', 'money'),
            $this->col('revenue', 'Revenue (ex-VAT)', 'money'),
            $this->col('cogs', 'Cost of goods', 'money'),
            $this->col('gross_profit', 'Gross profit', 'money'),
            $this->col('margin', 'Margin', 'percent'),
        ], $rows, $totals, [
            'Net sales = gross sales − discounts − VAT removed from Senior/PWD items − returns (VAT-inclusive). Revenue excludes VAT.',
            'Returns are counted on the day they were refunded. Voided sales are excluded.',
        ]);
    }

    /**
     * KPIs for the period (net sales, gross profit, expenses, cash collected…).
     *
     * @return array<string, string|int>
     */
    public function kpis(ReportFilters $f): array
    {
        $s = $this->validSales($f)->selectRaw('COUNT(*) AS n, SUM(gross_amount) AS gross,
            SUM(line_discount_total + transaction_discount_total + sc_pwd_discount_total + loyalty_discount) AS discounts,
            SUM(total_amount) AS total, SUM(vat_amount) AS vat, SUM(cogs_total) AS cogs')->first();
        $r = $this->refunds($f)->selectRaw('SUM(total) AS total, SUM(vat_amount) AS vat, SUM(cogs_reversed) AS cogs')->first();
        $figures = $this->salesFigures($s, $r);
        $expenses = $this->expenseTotal($f);
        $collected = Money::sum(array_column($this->payments($f)->rows, 'net_collected'));

        return $figures + [
            'average_sale' => (int) $figures['transactions'] > 0
                ? (string) Money::of($figures['net_sales'])->dividedBy((string) $figures['transactions']) : '0.00',
            'expenses' => (string) $expenses,
            'operating_profit' => (string) Money::of($figures['gross_profit'])->minus($expenses),
            'cash_collected' => (string) $collected,
        ];
    }

    /* ================================================================ products / categories */

    public function products(ReportFilters $f, string $sort = 'net_sales'): Report
    {
        $rows = $this->productFigures($f);
        usort($rows, fn ($a, $b) => $sort === 'qty'
            ? BigDecimal::of($b['qty'])->compareTo($a['qty'])
            : Money::of($b['net_sales'])->compareTo($a['net_sales']));
        $limited = count($rows) > 1000;
        $rows = array_slice($rows, 0, 1000);

        return new Report($sort === 'qty' ? 'fast-moving' : 'products', $sort === 'qty' ? 'Fast-moving products' : 'Sales by product', [
            $this->col('sku', 'SKU', 'text'),
            $this->col('name', 'Product', 'text'),
            $this->col('category', 'Category', 'text'),
            $this->col('qty', 'Qty sold', 'qty'),
            $this->col('unit', 'Unit', 'text'),
            $this->col('net_sales', 'Net sales', 'money'),
            $this->col('vat', 'VAT', 'money'),
            $this->col('revenue', 'Revenue (ex-VAT)', 'money'),
            $this->col('cogs', 'Cost of goods', 'money'),
            $this->col('gross_profit', 'Gross profit', 'money'),
            $this->col('margin', 'Margin', 'percent'),
        ], $rows, $this->sumColumns($rows, ['net_sales', 'vat', 'revenue', 'cogs', 'gross_profit'], ['name' => 'Total']), array_filter([
            'Quantities are in each product\'s base unit, net of returns.',
            $limited ? 'Showing the top 1,000 products.' : null,
        ]));
    }

    public function categories(ReportFilters $f): Report
    {
        $grouped = [];
        foreach ($this->productFigures($f) as $p) {
            $key = $p['category'] ?: 'Uncategorized';
            $g = $grouped[$key] ?? ['category' => $key, 'products' => 0, 'net_sales' => Money::zero(), 'vat' => Money::zero(), 'cogs' => Money::zero()];
            $g['products']++;
            $g['net_sales'] = $g['net_sales']->plus($p['net_sales']);
            $g['vat'] = $g['vat']->plus($p['vat']);
            $g['cogs'] = $g['cogs']->plus($p['cogs']);
            $grouped[$key] = $g;
        }
        $rows = array_map(function ($g) {
            $revenue = $g['net_sales']->minus($g['vat']);
            $profit = $revenue->minus($g['cogs']);

            return ['category' => $g['category'], 'products' => $g['products'], 'net_sales' => (string) $g['net_sales'], 'vat' => (string) $g['vat'],
                'revenue' => (string) $revenue, 'cogs' => (string) $g['cogs'], 'gross_profit' => (string) $profit, 'margin' => $this->margin($profit, $revenue)];
        }, array_values($grouped));
        usort($rows, fn ($a, $b) => Money::of($b['net_sales'])->compareTo($a['net_sales']));

        return new Report('categories', 'Sales by category', [
            $this->col('category', 'Category', 'text'),
            $this->col('products', 'Products sold', 'int'),
            $this->col('net_sales', 'Net sales', 'money'),
            $this->col('vat', 'VAT', 'money'),
            $this->col('revenue', 'Revenue (ex-VAT)', 'money'),
            $this->col('cogs', 'Cost of goods', 'money'),
            $this->col('gross_profit', 'Gross profit', 'money'),
            $this->col('margin', 'Margin', 'percent'),
        ], $rows, $this->sumColumns($rows, ['net_sales', 'vat', 'revenue', 'cogs', 'gross_profit'], ['category' => 'Total']), ['Sub-categories are counted under their main category.']);
    }

    /* ================================================================ branches / cashiers */

    public function branches(ReportFilters $f): Report
    {
        $sales = $this->validSales($f)->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS n, SUM(gross_amount) AS gross,
            SUM(line_discount_total + transaction_discount_total + sc_pwd_discount_total + loyalty_discount) AS discounts,
            SUM(total_amount) AS total, SUM(vat_amount) AS vat, SUM(cogs_total) AS cogs')->get()->keyBy('branch_id');
        $refunds = $this->refunds($f)->groupBy('refunds.branch_id')
            ->selectRaw('refunds.branch_id AS branch_id, SUM(total) AS total, SUM(vat_amount) AS vat, SUM(cogs_reversed) AS cogs')->get()->keyBy('branch_id');

        $rows = $f->branches->map(fn ($b) => ['branch' => $b->name] + $this->salesFigures($sales[$b->id] ?? null, $refunds[$b->id] ?? null))->values()->all();

        return new Report('branches', 'Sales by branch', [
            $this->col('branch', 'Branch', 'text'),
            $this->col('transactions', 'Sales', 'int'),
            $this->col('gross_sales', 'Gross sales', 'money'),
            $this->col('discounts', 'Discounts', 'money'),
            $this->col('returns', 'Returns', 'money'),
            $this->col('net_sales', 'Net sales', 'money'),
            $this->col('vat', 'VAT', 'money'),
            $this->col('revenue', 'Revenue (ex-VAT)', 'money'),
            $this->col('cogs', 'Cost of goods', 'money'),
            $this->col('gross_profit', 'Gross profit', 'money'),
            $this->col('margin', 'Margin', 'percent'),
        ], $rows, ['branch' => 'Total'] + $this->sumFigures($rows));
    }

    public function cashiers(ReportFilters $f): Report
    {
        $t = $this->tenant->requireId();
        $sales = $this->validSales($f)->groupBy('cashier_id')->selectRaw('cashier_id, COUNT(*) AS n, SUM(total_amount) AS total,
            SUM(line_discount_total + transaction_discount_total + sc_pwd_discount_total + loyalty_discount) AS discounts')->get()->keyBy('cashier_id');
        $voids = $this->salesInRange($f)->where('status', 'voided')->groupBy('cashier_id')
            ->selectRaw('cashier_id, COUNT(*) AS n, SUM(total_amount) AS total')->get()->keyBy('cashier_id');
        $refunds = $this->refunds($f)->groupBy('refunds.processed_by')
            ->selectRaw('refunds.processed_by AS user_id, COUNT(*) AS n, SUM(total) AS total')->get()->keyBy('user_id');

        $ids = $sales->keys()->merge($voids->keys())->merge($refunds->keys())->unique()->values();
        $names = DB::table('users')->where('tenant_id', $t)->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(function ($id) use ($sales, $voids, $refunds, $names) {
            $s = $sales[$id] ?? null;
            $n = (int) ($s->n ?? 0);
            $net = Money::of($s->total ?? '0');

            return [
                'cashier' => $names[$id] ?? 'Unknown',
                'transactions' => $n,
                'net_sales' => (string) $net,
                'average_sale' => $n > 0 ? (string) $net->dividedBy((string) $n) : '0.00',
                'discounts' => (string) Money::of($s->discounts ?? '0'),
                'voids' => (int) ($voids[$id]->n ?? 0),
                'void_amount' => (string) Money::of($voids[$id]->total ?? '0'),
                'refunds' => (int) ($refunds[$id]->n ?? 0),
                'refund_amount' => (string) Money::of($refunds[$id]->total ?? '0'),
            ];
        })->sort(fn ($a, $b) => Money::of($b['net_sales'])->compareTo($a['net_sales']))->values()->all();

        return new Report('cashiers', 'Cashier performance', [
            $this->col('cashier', 'Cashier', 'text'),
            $this->col('transactions', 'Sales', 'int'),
            $this->col('net_sales', 'Sales amount', 'money'),
            $this->col('average_sale', 'Average sale', 'money'),
            $this->col('discounts', 'Discounts given', 'money'),
            $this->col('voids', 'Voids', 'int'),
            $this->col('void_amount', 'Voided amount', 'money'),
            $this->col('refunds', 'Refunds processed', 'int'),
            $this->col('refund_amount', 'Refunded amount', 'money'),
        ], $rows, $this->sumColumns($rows, ['transactions', 'net_sales', 'discounts', 'voids', 'void_amount', 'refunds', 'refund_amount'], ['cashier' => 'Total']), [
            'Sales amount is before returns; refunds are listed under the cashier who processed them.',
        ]);
    }

    /* ================================================================ payments / discounts / tax */

    public function payments(ReportFilters $f): Report
    {
        $t = $this->tenant->requireId();
        $rows = DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->join('cash_register_sessions as s', 's.id', '=', 'payments.cash_register_session_id')
            ->where('payments.tenant_id', $t)->where('payments.status', 'confirmed')
            ->whereIn('s.branch_id', $f->branchIds())
            ->whereBetween('payments.paid_at', [$f->fromUtc(), $f->toUtc()])
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type', 'payments.direction')
            ->selectRaw('payment_methods.id AS id, payment_methods.name AS method, payment_methods.type AS type, payments.direction AS direction,
                COUNT(*) AS n, SUM(payments.amount) AS amount')
            ->get();
        $account = DB::table('customer_ledger_entries as e')
            ->join('payment_methods', 'payment_methods.id', '=', 'e.payment_method_id')
            ->where('e.tenant_id', $t)->where('e.type', 'payment')
            ->whereIn('e.branch_id', $f->branchIds())
            ->whereBetween('e.created_at', [$f->fromUtc(), $f->toUtc()])
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type')
            ->selectRaw('payment_methods.id AS id, payment_methods.name AS method, payment_methods.type AS type, SUM(ABS(e.amount)) AS amount')
            ->get()->keyBy('id');

        $methods = $rows->groupBy('id');
        $out = [];
        foreach ($methods->keys()->merge($account->keys())->unique() as $id) {
            $group = $methods[$id] ?? collect();
            $first = $group->first() ?? $account[$id];
            $in = Money::sum($group->where('direction', 'in')->pluck('amount')->all());
            $refunded = Money::sum($group->where('direction', 'out')->pluck('amount')->all());
            $acct = Money::of($account[$id]->amount ?? '0');
            $credit = $first->type === 'credit';
            $out[] = [
                'method' => $credit ? "{$first->method} (not collected)" : $first->method,
                'transactions' => (int) $group->where('direction', 'in')->sum('n'),
                'sales_received' => (string) $in,
                'refunds_paid' => (string) $refunded,
                'account_payments' => (string) $acct,
                'net_collected' => $credit ? '0.00' : (string) $in->minus($refunded)->plus($acct),
            ];
        }

        return new Report('payments', 'Payment methods', [
            $this->col('method', 'Payment method', 'text'),
            $this->col('transactions', 'Payments', 'int'),
            $this->col('sales_received', 'Received for sales', 'money'),
            $this->col('refunds_paid', 'Refunds paid out', 'money'),
            $this->col('account_payments', 'Account payments received', 'money'),
            $this->col('net_collected', 'Net collected', 'money'),
        ], $out, $this->sumColumns($out, ['transactions', 'sales_received', 'refunds_paid', 'account_payments', 'net_collected'], ['method' => 'Total']), [
            'Charges to customer accounts are sales on credit, not money collected; account payments are listed when received.',
        ]);
    }

    public function discounts(ReportFilters $f): Report
    {
        $labels = self::STATUTORY + ['manual' => 'Manual discount', 'preset' => 'Preset discount', 'customer_group' => 'Customer group', 'loyalty' => 'Points redeemed'];
        $rows = DB::table('sale_discounts')
            ->join('sales', 'sales.id', '=', 'sale_discounts.sale_id')
            ->leftJoin('discount_presets as p', 'p.id', '=', 'sale_discounts.discount_preset_id')
            ->where('sale_discounts.tenant_id', $this->tenant->requireId())
            ->tap(fn ($q) => $this->scopeSales($q, $f))->where('sales.status', '!=', 'voided')
            ->groupBy('sale_discounts.type', 'sale_discounts.scope', 'p.name')
            ->selectRaw('sale_discounts.type AS type, sale_discounts.scope AS scope, p.name AS preset, COUNT(DISTINCT sale_discounts.sale_id) AS sales, SUM(sale_discounts.amount) AS amount')
            ->orderByDesc('amount')->get()
            ->map(fn ($d) => [
                'discount' => ($labels[$d->type] ?? ucfirst((string) $d->type)).($d->preset ? " — {$d->preset}" : ''),
                'applies_to' => $d->scope === 'line' ? 'Items' : 'Whole sale',
                'sales' => (int) $d->sales,
                'amount' => (string) Money::of($d->amount),
            ])->all();

        return new Report('discounts', 'Discounts', [
            $this->col('discount', 'Discount', 'text'),
            $this->col('applies_to', 'Applies to', 'text'),
            $this->col('sales', 'Sales', 'int'),
            $this->col('amount', 'Amount', 'money'),
        ], $rows, $this->sumColumns($rows, ['amount'], ['discount' => 'Total']), ['Discounts of voided sales are excluded; returns do not reduce these amounts.']);
    }

    public function tax(ReportFilters $f): Report
    {
        $day = "DATE({$f->local('sales.sold_at')})";
        $sales = $this->validSales($f)->groupByRaw($day)->selectRaw("{$day} AS day, SUM(vatable_sales) AS vatable, SUM(vat_amount) AS vat,
            SUM(vat_exempt_sales) AS exempt, SUM(zero_rated_sales) AS zero, SUM(sc_pwd_discount_total) AS sc")->get()->keyBy('day');
        $rday = "DATE({$f->local('refunds.refunded_at')})";
        $refunds = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sale_items', 'sale_items.id', '=', 'refund_items.sale_item_id')
            ->where('refund_items.tenant_id', $this->tenant->requireId())->whereIn('refunds.branch_id', $f->branchIds())
            ->whereBetween('refunds.refunded_at', [$f->fromUtc(), $f->toUtc()])
            ->groupByRaw("{$rday}, sale_items.tax_class")
            ->selectRaw("{$rday} AS day, sale_items.tax_class AS tax_class, SUM(refund_items.amount) AS amount, SUM(refund_items.vat_amount) AS vat,
                SUM(CASE WHEN sale_items.qty > 0 THEN ROUND(sale_items.sc_pwd_discount * refund_items.qty / sale_items.qty, 2) ELSE 0 END) AS sc")
            ->get()->groupBy('day');

        $rows = [];
        foreach ($this->periods(new ReportFilters($f->from, $f->to, $f->branches, $f->timezone, 'day'), $sales->keys()->merge($refunds->keys())) as $d) {
            $s = $sales[$d] ?? null;
            $r = ($refunds[$d] ?? collect())->keyBy('tax_class');
            $ra = fn (string $class, string $field) => Money::of($r[$class]->{$field} ?? '0');
            $rows[] = [
                'date' => $d,
                'vatable_sales' => (string) Money::of($s->vatable ?? '0')->minus($ra('vatable', 'amount')->minus($ra('vatable', 'vat'))),
                'vat' => (string) Money::of($s->vat ?? '0')->minus($ra('vatable', 'vat')),
                // SC/PWD lines: the exempt sale is the price before the discount, so its share comes off too.
                'vat_exempt_sales' => (string) Money::of($s->exempt ?? '0')->minus($ra('vat_exempt', 'amount'))->minus($ra('vat_exempt', 'sc'))->minus($ra('non_vat', 'amount')),
                'zero_rated_sales' => (string) Money::of($s->zero ?? '0')->minus($ra('zero_rated', 'amount')),
                'sc_pwd_discount' => (string) Money::of($s->sc ?? '0'),
            ];
        }

        return new Report('tax', 'VAT and tax summary', [
            $this->col('date', 'Date', 'date'),
            $this->col('vatable_sales', 'VATable sales', 'money'),
            $this->col('vat', 'Output VAT', 'money'),
            $this->col('vat_exempt_sales', 'VAT-exempt sales', 'money'),
            $this->col('zero_rated_sales', 'Zero-rated sales', 'money'),
            $this->col('sc_pwd_discount', 'SC/PWD discount', 'money'),
        ], $rows, $this->sumColumns($rows, ['vatable_sales', 'vat', 'vat_exempt_sales', 'zero_rated_sales', 'sc_pwd_discount'], ['date' => 'Total']), [
            'VATable sales exclude VAT. Returns reduce the day they were refunded. A non-VAT business records its sales as VAT-exempt (non-VAT).',
        ]);
    }

    /** Senior Citizen / PWD sales book. */
    public function scPwd(ReportFilters $f): Report
    {
        $rows = SaleDiscount::query()
            ->join('sales', 'sales.id', '=', 'sale_discounts.sale_id')
            ->whereIn('sale_discounts.type', array_keys(self::STATUTORY))
            ->tap(fn ($q) => $this->scopeSales($q->getQuery(), $f))->where('sales.status', '!=', 'voided')
            ->orderBy('sales.sold_at')
            ->select('sale_discounts.*', 'sales.invoice_no', 'sales.sold_at', 'sales.gross_amount', 'sales.total_amount')
            ->limit(5000)->get()
            ->map(fn ($d) => [
                'date' => CarbonImmutable::parse($d->getAttribute('sold_at'))->setTimezone($f->timezone)->toDateString(),
                'invoice_no' => $d->getAttribute('invoice_no'),
                'type' => self::STATUTORY[$d->type] ?? $d->type,
                'name' => $d->beneficiary_name,
                'id_no' => $d->beneficiary_id_no ? '••••'.mb_substr($d->beneficiary_id_no, -4) : null,
                'tin' => $d->beneficiary_tin,
                'discount' => (string) Money::of($d->amount),
                'invoice_total' => (string) Money::of($d->getAttribute('total_amount')),
            ])->all();

        return new Report('sc-pwd', 'Senior Citizen / PWD sales book', [
            $this->col('date', 'Date', 'date'),
            $this->col('invoice_no', 'Invoice', 'text'),
            $this->col('type', 'Type', 'text'),
            $this->col('name', 'Name', 'text'),
            $this->col('id_no', 'ID no.', 'text'),
            $this->col('tin', 'TIN', 'text'),
            $this->col('discount', 'Discount', 'money'),
            $this->col('invoice_total', 'Invoice total', 'money'),
        ], $rows, $this->sumColumns($rows, ['discount'], ['date' => 'Total']), ['ID numbers are shown masked. One row per discounted item.']);
    }

    /* ================================================================ inventory */

    public function inventoryValuation(ReportFilters $f): Report
    {
        $rows = DB::table('inventory_balances as b')
            ->join('branches', 'branches.id', '=', 'b.branch_id')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent', 'parent.id', '=', 'c.parent_id')
            ->where('b.tenant_id', $this->tenant->requireId())->whereIn('b.branch_id', $f->branchIds())
            ->where(fn ($q) => $q->where('b.qty_on_hand', '!=', 0)->orWhere('b.total_cost', '!=', 0))
            ->orderBy('branches.name')->orderBy('p.name')
            ->selectRaw('branches.name AS branch, v.sku, p.name AS product, v.name AS variant, COALESCE(parent.name, c.name) AS category,
                u.abbreviation AS unit, b.qty_on_hand AS qty, b.total_cost AS value')
            ->limit(20000)->get()
            ->map(fn ($r) => [
                'branch' => $r->branch,
                'sku' => $r->sku,
                'name' => $r->variant ? "{$r->product} - {$r->variant}" : $r->product,
                'category' => $r->category,
                'qty' => (string) BigDecimal::of($r->qty)->toScale(3),
                'unit' => $r->unit,
                'unit_cost' => BigDecimal::of($r->qty)->isPositive() ? (string) BigDecimal::of($r->value)->dividedBy($r->qty, 4, RoundingMode::HalfUp) : null,
                'value' => (string) Money::of($r->value),
            ])->all();

        return new Report('inventory-valuation', 'Inventory valuation', [
            $this->col('branch', 'Branch', 'text'),
            $this->col('sku', 'SKU', 'text'),
            $this->col('name', 'Product', 'text'),
            $this->col('category', 'Category', 'text'),
            $this->col('qty', 'On hand', 'qty'),
            $this->col('unit', 'Unit', 'text'),
            $this->col('unit_cost', 'Average cost', 'money'),
            $this->col('value', 'Value (FIFO)', 'money'),
        ], $rows, $this->sumColumns($rows, ['value'], ['branch' => 'Total']), ['Current stock (the date filter does not apply). Valued at FIFO cost.']);
    }

    public function lowStock(ReportFilters $f): Report
    {
        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->crossJoin('branches')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'v.id')->on('b.branch_id', '=', 'branches.id'))
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('v.tenant_id', $this->tenant->requireId())->whereIn('branches.id', $f->branchIds())
            ->whereNull('p.deleted_at')->where('p.is_active', true)->where('v.is_active', true)
            ->where('p.type', 'standard')->where('p.track_inventory', true)
            ->where('v.reorder_level', '>', 0)
            ->whereRaw('COALESCE(b.qty_on_hand, 0) <= v.reorder_level')
            ->orderByRaw('COALESCE(b.qty_on_hand, 0) / v.reorder_level')
            ->selectRaw('branches.name AS branch, v.sku, p.name AS product, v.name AS variant, u.abbreviation AS unit,
                COALESCE(b.qty_on_hand, 0) AS qty, v.reorder_level AS reorder')
            ->limit(5000)->get()
            ->map(fn ($r) => [
                'branch' => $r->branch,
                'sku' => $r->sku,
                'name' => $r->variant ? "{$r->product} - {$r->variant}" : $r->product,
                'qty' => (string) BigDecimal::of($r->qty)->toScale(3),
                'unit' => $r->unit,
                'reorder_level' => (string) BigDecimal::of($r->reorder)->toScale(3),
                'status' => BigDecimal::of($r->qty)->isPositive() ? 'Low' : 'Out of stock',
                'suggested' => (string) BigDecimal::max(BigDecimal::of($r->reorder), BigDecimal::of($r->reorder)->multipliedBy(2)->minus($r->qty))->toScale(0, RoundingMode::Up),
            ])->all();

        return new Report('low-stock', 'Low-stock products', [
            $this->col('branch', 'Branch', 'text'),
            $this->col('sku', 'SKU', 'text'),
            $this->col('name', 'Product', 'text'),
            $this->col('qty', 'On hand', 'qty'),
            $this->col('unit', 'Unit', 'text'),
            $this->col('reorder_level', 'Reorder level', 'qty'),
            $this->col('status', 'Status', 'text'),
            $this->col('suggested', 'Suggested order', 'qty'),
        ], $rows, null, ['Current stock. Suggested order brings stock up to twice the reorder level.']);
    }

    public function slowMoving(ReportFilters $f): Report
    {
        $t = $this->tenant->requireId();
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.tenant_id', $t)->tap(fn ($q) => $this->scopeSales($q, $f))->where('sales.status', '!=', 'voided')
            ->groupBy('sales.branch_id', 'sale_items.product_variant_id')
            ->selectRaw('sales.branch_id AS branch_id, sale_items.product_variant_id AS variant_id, SUM(sale_items.base_qty) AS qty');
        $last = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.tenant_id', $t)->where('sales.status', '!=', 'voided')->whereIn('sales.branch_id', $f->branchIds())
            ->groupBy('sales.branch_id', 'sale_items.product_variant_id')
            ->selectRaw('sales.branch_id AS branch_id, sale_items.product_variant_id AS variant_id, MAX(sales.sold_at) AS last_sold');

        $rows = DB::table('inventory_balances as b')
            ->join('branches', 'branches.id', '=', 'b.branch_id')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoinSub($sold, 'sold', fn ($j) => $j->on('sold.variant_id', '=', 'b.product_variant_id')->on('sold.branch_id', '=', 'b.branch_id'))
            ->leftJoinSub($last, 'last', fn ($j) => $j->on('last.variant_id', '=', 'b.product_variant_id')->on('last.branch_id', '=', 'b.branch_id'))
            ->where('b.tenant_id', $t)->whereIn('b.branch_id', $f->branchIds())->where('b.qty_on_hand', '>', 0)
            ->whereNull('p.deleted_at')->where('p.is_active', true)
            ->orderByRaw('COALESCE(sold.qty, 0)')->orderByDesc('b.total_cost')
            ->selectRaw('branches.name AS branch, v.sku, p.name AS product, v.name AS variant, u.abbreviation AS unit,
                b.qty_on_hand AS qty, b.total_cost AS value, COALESCE(sold.qty, 0) AS sold, last.last_sold')
            ->limit(500)->get()
            ->map(fn ($r) => [
                'branch' => $r->branch,
                'sku' => $r->sku,
                'name' => $r->variant ? "{$r->product} - {$r->variant}" : $r->product,
                'qty_sold' => (string) BigDecimal::of($r->sold)->toScale(3),
                'on_hand' => (string) BigDecimal::of($r->qty)->toScale(3),
                'unit' => $r->unit,
                'value' => (string) Money::of($r->value),
                'last_sold' => $r->last_sold ? CarbonImmutable::parse($r->last_sold, 'UTC')->setTimezone($f->timezone)->toDateString() : null,
            ])->all();

        return new Report('slow-moving', 'Slow-moving products', [
            $this->col('branch', 'Branch', 'text'),
            $this->col('sku', 'SKU', 'text'),
            $this->col('name', 'Product', 'text'),
            $this->col('qty_sold', 'Sold in period', 'qty'),
            $this->col('on_hand', 'On hand', 'qty'),
            $this->col('unit', 'Unit', 'text'),
            $this->col('value', 'Stock value', 'money'),
            $this->col('last_sold', 'Last sold', 'date'),
        ], $rows, null, ['Items in stock that sold the least in the period (most stock value first among equals). Top 500.']);
    }

    public function stockMovements(ReportFilters $f): Report
    {
        $labels = [
            'opening' => 'Opening stock', 'purchase_receipt' => 'Received', 'sale' => 'Sale', 'sale_void' => 'Sale voided', 'return' => 'Customer return',
            'adjustment_in' => 'Adjustment in', 'adjustment_out' => 'Adjustment out', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in',
            'count_variance' => 'Count variance', 'receipt_reversal' => 'Receipt reversed',
        ];
        $rows = DB::table('stock_movements as m')
            ->join('branches', 'branches.id', '=', 'm.branch_id')
            ->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('users', 'users.id', '=', 'm.user_id')
            ->where('m.tenant_id', $this->tenant->requireId())->whereIn('m.branch_id', $f->branchIds())
            ->whereBetween('m.occurred_at', [$f->fromUtc(), $f->toUtc()])
            ->orderBy('m.occurred_at')->orderBy('m.id')
            ->selectRaw('m.occurred_at, branches.name AS branch, v.sku, p.name AS product, v.name AS variant, m.type, m.qty, m.unit_cost, m.total_cost, m.reason, users.name AS user')
            ->limit(10000)->get()
            ->map(fn ($r) => [
                'date' => CarbonImmutable::parse($r->occurred_at, 'UTC')->setTimezone($f->timezone)->format('Y-m-d H:i'),
                'branch' => $r->branch,
                'sku' => $r->sku,
                'name' => $r->variant ? "{$r->product} - {$r->variant}" : $r->product,
                'type' => $labels[$r->type] ?? $r->type,
                'qty' => (string) BigDecimal::of($r->qty)->toScale(3),
                'unit_cost' => (string) BigDecimal::of($r->unit_cost)->toScale(4),
                'total_cost' => (string) Money::of($r->total_cost),
                'reason' => $r->reason,
                'user' => $r->user,
            ])->all();

        return new Report('stock-movements', 'Stock movements', [
            $this->col('date', 'Date', 'text'),
            $this->col('branch', 'Branch', 'text'),
            $this->col('sku', 'SKU', 'text'),
            $this->col('name', 'Product', 'text'),
            $this->col('type', 'Movement', 'text'),
            $this->col('qty', 'Qty', 'qty'),
            $this->col('unit_cost', 'Unit cost', 'money'),
            $this->col('total_cost', 'Total cost', 'money'),
            $this->col('reason', 'Reference', 'text'),
            $this->col('user', 'By', 'text'),
        ], $rows, null, ['Quantities in base units; negative = out. Up to 10,000 movements.']);
    }

    public function purchases(ReportFilters $f): Report
    {
        $rows = DB::table('goods_receipts as g')
            ->join('branches', 'branches.id', '=', 'g.branch_id')
            ->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')
            ->where('g.tenant_id', $this->tenant->requireId())->whereIn('g.branch_id', $f->branchIds())
            ->whereBetween('g.received_at', [$f->fromUtc(), $f->toUtc()])
            ->where('g.status', 'posted')
            ->orderBy('g.received_at')
            ->selectRaw('g.received_at, g.number, po.number AS po_number, s.name AS supplier, g.supplier_invoice_no, branches.name AS branch,
                g.subtotal, g.tax_total, g.total, (SELECT COUNT(*) FROM goods_receipt_items i WHERE i.goods_receipt_id = g.id) AS items')
            ->get()
            ->map(fn ($r) => [
                'date' => CarbonImmutable::parse($r->received_at, 'UTC')->setTimezone($f->timezone)->toDateString(),
                'number' => $r->number,
                'po_number' => $r->po_number,
                'supplier' => $r->supplier,
                'supplier_invoice_no' => $r->supplier_invoice_no,
                'branch' => $r->branch,
                'items' => (int) $r->items,
                'net' => (string) Money::of($r->subtotal),
                'input_vat' => (string) Money::of($r->tax_total),
                'total' => (string) Money::of($r->total),
            ])->all();

        return new Report('purchases', 'Purchase history', [
            $this->col('date', 'Received', 'date'),
            $this->col('number', 'Receipt', 'text'),
            $this->col('po_number', 'PO', 'text'),
            $this->col('supplier', 'Supplier', 'text'),
            $this->col('supplier_invoice_no', 'Supplier invoice', 'text'),
            $this->col('branch', 'Branch', 'text'),
            $this->col('items', 'Lines', 'int'),
            $this->col('net', 'Net of VAT', 'money'),
            $this->col('input_vat', 'Input VAT', 'money'),
            $this->col('total', 'Total', 'money'),
        ], $rows, $this->sumColumns($rows, ['net', 'input_vat', 'total'], ['date' => 'Total']), ['Goods received (posted receipts). Reversed receipts are excluded.']);
    }

    /**
     * What is owed to each supplier today, by how overdue it is (aging).
     */
    public function payables(ReportFilters $f): Report
    {
        $today = CarbonImmutable::now($f->timezone)->toDateString();
        $open = DB::table('supplier_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->where('b.tenant_id', $this->tenant->requireId())->whereIn('b.status', ['open', 'partially_paid'])
            ->selectRaw('s.id, s.name, b.total - b.amount_paid AS due, DATEDIFF(?, b.due_date) AS late', [$today])
            ->get();

        $rows = $open->groupBy('id')->map(function ($bills) {
            $bucket = fn (callable $test) => (string) Money::sum($bills->filter(fn ($b) => $test((int) $b->late))->pluck('due')->all());

            return [
                'supplier' => $bills->first()->name,
                'bills' => $bills->count(),
                'current' => $bucket(fn ($d) => $d <= 0),
                'days_1_30' => $bucket(fn ($d) => $d >= 1 && $d <= 30),
                'days_31_60' => $bucket(fn ($d) => $d >= 31 && $d <= 60),
                'days_61_90' => $bucket(fn ($d) => $d >= 61 && $d <= 90),
                'over_90' => $bucket(fn ($d) => $d > 90),
                'balance' => (string) Money::sum($bills->pluck('due')->all()),
            ];
        })->sort(fn ($a, $b) => Money::of($b['balance'])->compareTo($a['balance']))->values()->all();

        return new Report('payables', 'Supplier balances (aging)', [
            $this->col('supplier', 'Supplier', 'text'),
            $this->col('bills', 'Open bills', 'int'),
            $this->col('current', 'Not yet due', 'money'),
            $this->col('days_1_30', '1–30 days late', 'money'),
            $this->col('days_31_60', '31–60 days late', 'money'),
            $this->col('days_61_90', '61–90 days late', 'money'),
            $this->col('over_90', 'Over 90 days', 'money'),
            $this->col('balance', 'Balance', 'money'),
        ], $rows, $this->sumColumns($rows, ['bills', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'balance'], ['supplier' => 'Total']), [
            'Unpaid bills as of today, by days past their due date. All branches.',
        ]);
    }

    public function expenses(ReportFilters $f, string $groupBy = 'none'): Report
    {
        $base = $this->expenseQuery($f);
        if ($groupBy === 'category') {
            $rows = $base->groupBy('c.name')->selectRaw('c.name AS category, COUNT(*) AS n, SUM(e.amount) AS amount')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->n, 'amount' => (string) Money::of($r->amount)])->all();

            return new Report('expenses', 'Expenses by category', [
                $this->col('category', 'Category', 'text'),
                $this->col('count', 'Expenses', 'int'),
                $this->col('amount', 'Amount', 'money'),
            ], $rows, $this->sumColumns($rows, ['count', 'amount'], ['category' => 'Total']), ['Approved and paid expenses, by expense date.']);
        }

        $rows = $base->leftJoin('payment_methods as m', 'm.id', '=', 'e.payment_method_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.submitted_by')
            ->orderBy('e.expense_date')->orderBy('e.id')
            ->selectRaw('e.expense_date, e.number, c.name AS category, branches.name AS branch, e.vendor, e.reference_no, m.name AS method, e.status, u.name AS submitted_by, e.amount')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->expense_date,
                'number' => $r->number,
                'category' => $r->category,
                'branch' => $r->branch,
                'vendor' => $r->vendor,
                'reference_no' => $r->reference_no,
                'method' => $r->method,
                'status' => ucfirst((string) $r->status),
                'submitted_by' => $r->submitted_by,
                'amount' => (string) Money::of($r->amount),
            ])->all();

        return new Report('expenses', 'Expenses', [
            $this->col('date', 'Date', 'date'),
            $this->col('number', 'No.', 'text'),
            $this->col('category', 'Category', 'text'),
            $this->col('branch', 'Branch', 'text'),
            $this->col('vendor', 'Paid to', 'text'),
            $this->col('reference_no', 'Reference', 'text'),
            $this->col('method', 'Paid by', 'text'),
            $this->col('status', 'Status', 'text'),
            $this->col('submitted_by', 'Recorded by', 'text'),
            $this->col('amount', 'Amount', 'money'),
        ], $rows, $this->sumColumns($rows, ['amount'], ['date' => 'Total']), ['Approved and paid expenses, by expense date.']);
    }

    /* ================================================================ dashboard pieces */

    /**
     * @return list<array{date: string, net_sales: string, transactions: int}>
     */
    public function dailySeries(ReportFilters $f): array
    {
        return array_map(fn ($r) => ['date' => $r['period'], 'net_sales' => $r['net_sales'], 'transactions' => $r['transactions']],
            $this->sales(new ReportFilters($f->from, $f->to, $f->branches, $f->timezone, 'day'))->rows);
    }

    /* ================================================================ building blocks */

    private function salesInRange(ReportFilters $f): Builder
    {
        return $this->scopeSales(DB::table('sales')->where('sales.tenant_id', $this->tenant->requireId()), $f);
    }

    private function scopeSales(Builder $q, ReportFilters $f): Builder
    {
        return $q->whereIn('sales.branch_id', $f->branchIds())->whereBetween('sales.sold_at', [$f->fromUtc(), $f->toUtc()]);
    }

    private function validSales(ReportFilters $f): Builder
    {
        return $this->salesInRange($f)->where('sales.status', '!=', 'voided');
    }

    private function refunds(ReportFilters $f): Builder
    {
        return DB::table('refunds')->where('refunds.tenant_id', $this->tenant->requireId())
            ->whereIn('refunds.branch_id', $f->branchIds())
            ->whereBetween('refunds.refunded_at', [$f->fromUtc(), $f->toUtc()]);
    }

    private function expenseQuery(ReportFilters $f): Builder
    {
        return DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->join('branches', 'branches.id', '=', 'e.branch_id')
            ->where('e.tenant_id', $this->tenant->requireId())->whereIn('e.branch_id', $f->branchIds())
            ->whereIn('e.status', ['approved', 'paid'])
            ->whereBetween('e.expense_date', [$f->from->toDateString(), $f->to->toDateString()]);
    }

    private function expenseTotal(ReportFilters $f): Money
    {
        return Money::of($this->expenseQuery($f)->sum('e.amount') ?: '0');
    }

    /**
     * @return array<string, string|int>
     */
    private function salesFigures(?object $s, ?object $r): array
    {
        $gross = Money::of($s->gross ?? '0');
        $discounts = Money::of($s->discounts ?? '0');
        $charged = Money::of($s->total ?? '0');
        $returns = Money::of($r->total ?? '0');
        $net = $charged->minus($returns);
        $vat = Money::of($s->vat ?? '0')->minus($r->vat ?? '0');
        $revenue = $net->minus($vat);
        $cogs = Money::of($s->cogs ?? '0')->minus($r->cogs ?? '0');
        $profit = $revenue->minus($cogs);

        return [
            'transactions' => (int) ($s->n ?? 0),
            'gross_sales' => (string) $gross,
            'discounts' => (string) $discounts,
            'sc_pwd_vat' => (string) $gross->minus($discounts)->minus($charged),
            'returns' => (string) $returns,
            'net_sales' => (string) $net,
            'vat' => (string) $vat,
            'revenue' => (string) $revenue,
            'cogs' => (string) $cogs,
            'gross_profit' => (string) $profit,
            'margin' => $this->margin($profit, $revenue),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string|int>
     */
    private function sumFigures(array $rows): array
    {
        $sum = $this->sumColumns($rows, ['transactions', 'gross_sales', 'discounts', 'sc_pwd_vat', 'returns', 'net_sales', 'vat', 'revenue', 'cogs', 'gross_profit'], []);
        $sum['margin'] = $this->margin(Money::of($sum['gross_profit'] ?? '0'), Money::of($sum['revenue'] ?? '0'));

        return $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $label
     * @return array<string, mixed>
     */
    private function sumColumns(array $rows, array $keys, array $label): array
    {
        $out = $label;
        foreach ($keys as $k) {
            $values = array_column($rows, $k);
            $out[$k] = $values !== [] && is_int($values[0]) ? array_sum($values) : (string) Money::sum(array_map(fn ($v) => (string) ($v ?? '0'), $values));
        }

        return $out;
    }

    private function margin(Money $profit, Money $revenue): ?string
    {
        if (! $revenue->isPositive()) {
            return null;
        }

        return (string) BigDecimal::of((string) $profit)->multipliedBy(100)->dividedBy((string) $revenue, 1, RoundingMode::HalfUp);
    }

    /**
     * Net figures per product (sales less returns).
     *
     * @return list<array<string, mixed>>
     */
    private function productFigures(ReportFilters $f): array
    {
        $t = $this->tenant->requireId();
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.tenant_id', $t)->tap(fn ($q) => $this->scopeSales($q, $f))->where('sales.status', '!=', 'voided')
            ->groupBy('sale_items.product_variant_id')
            ->selectRaw('sale_items.product_variant_id AS id, SUM(sale_items.base_qty) AS qty, SUM(sale_items.line_total) AS total,
                SUM(sale_items.vat_amount) AS vat, SUM(sale_items.cogs) AS cogs')
            ->get()->keyBy('id');
        $returned = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sale_items', 'sale_items.id', '=', 'refund_items.sale_item_id')
            ->where('refund_items.tenant_id', $t)->whereIn('refunds.branch_id', $f->branchIds())
            ->whereBetween('refunds.refunded_at', [$f->fromUtc(), $f->toUtc()])
            ->groupBy('sale_items.product_variant_id')
            ->selectRaw("sale_items.product_variant_id AS id, SUM(refund_items.base_qty) AS qty, SUM(refund_items.amount) AS total,
                SUM(refund_items.vat_amount) AS vat,
                SUM(CASE WHEN refund_items.restock_to = 'sellable' AND sale_items.base_qty > 0
                    THEN ROUND(sale_items.cogs * refund_items.base_qty / sale_items.base_qty, 2) ELSE 0 END) AS cogs")
            ->get()->keyBy('id');

        $ids = $sold->keys()->merge($returned->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $info = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent', 'parent.id', '=', 'c.parent_id')
            ->where('v.tenant_id', $t)->whereIn('v.id', $ids)
            ->selectRaw('v.id, v.sku, v.name AS variant, p.name AS product, u.abbreviation AS unit, COALESCE(parent.name, c.name) AS category')
            ->get()->keyBy('id');

        return $ids->map(function ($id) use ($sold, $returned, $info) {
            $s = $sold[$id] ?? null;
            $r = $returned[$id] ?? null;
            $i = $info[$id] ?? null;
            $net = Money::of($s->total ?? '0')->minus($r->total ?? '0');
            $vat = Money::of($s->vat ?? '0')->minus($r->vat ?? '0');
            $cogs = Money::of($s->cogs ?? '0')->minus($r->cogs ?? '0');
            $revenue = $net->minus($vat);
            $profit = $revenue->minus($cogs);

            return [
                'sku' => $i->sku ?? '',
                'name' => $i ? ($i->variant ? "{$i->product} - {$i->variant}" : $i->product) : 'Unknown',
                'category' => $i->category ?? null,
                'qty' => (string) BigDecimal::of($s->qty ?? '0')->minus($r->qty ?? '0')->toScale(3),
                'unit' => $i->unit ?? null,
                'net_sales' => (string) $net,
                'vat' => (string) $vat,
                'revenue' => (string) $revenue,
                'cogs' => (string) $cogs,
                'gross_profit' => (string) $profit,
                'margin' => $this->margin($profit, $revenue),
            ];
        })->values()->all();
    }

    private function periodSql(ReportFilters $f, string $local): string
    {
        return match ($f->groupBy) {
            'week' => "DATE_FORMAT({$local}, '%x-W%v')",
            'month' => "DATE_FORMAT({$local}, '%Y-%m')",
            'year' => "DATE_FORMAT({$local}, '%Y')",
            default => "DATE_FORMAT({$local}, '%Y-%m-%d')",
        };
    }

    private function periodLabel(string $groupBy): string
    {
        return ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'][$groupBy] ?? 'Date';
    }

    /**
     * Every period of the range (so days without sales show as zero), plus any key found.
     *
     * @param  Collection<int, mixed>  $found
     * @return list<string>
     */
    private function periods(ReportFilters $f, Collection $found): array
    {
        $keys = [];
        $cursor = $f->from->startOfDay();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($f->to) && $guard++ < 4000) {
            $keys[] = match ($f->groupBy) {
                'week' => $cursor->format('o-\WW'),
                'month' => $cursor->format('Y-m'),
                'year' => $cursor->format('Y'),
                default => $cursor->format('Y-m-d'),
            };
            $cursor = $cursor->addDay();
        }

        $all = array_values(array_unique(array_merge($keys, $found->map(fn ($k) => (string) $k)->all())));
        sort($all);

        return $all;
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    private function col(string $key, string $label, string $type): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
