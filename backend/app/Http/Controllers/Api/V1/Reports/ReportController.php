<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Services\Billing\PlanGate;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use App\Support\Api\ApiResponse;
use App\Support\Export\TableExporter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /reports/{report} — JSON, or a CSV / Excel download (format=csv|xlsx).
 * Each report has its permission(s) and plan feature; cost and profit
 * columns are dropped for users who may not see costs.
 */
class ReportController extends Controller
{
    /**
     * report => [permissions (any), plan feature|null, cost columns]
     */
    private const REPORTS = [
        'sales' => [['reports.sales'], null, ['cogs', 'gross_profit', 'margin']],
        'payments' => [['reports.sales'], null, []],
        'discounts' => [['reports.sales'], null, []],
        'tax' => [['reports.sales', 'reports.financial'], null, []],
        'sc-pwd' => [['reports.sales', 'reports.financial'], null, []],
        'products' => [['reports.sales'], 'advanced_reports', ['cogs', 'gross_profit', 'margin']],
        'fast-moving' => [['reports.sales', 'reports.inventory'], 'advanced_reports', ['cogs', 'gross_profit', 'margin']],
        'categories' => [['reports.sales'], 'advanced_reports', ['cogs', 'gross_profit', 'margin']],
        'branches' => [['reports.sales'], 'advanced_reports', ['cogs', 'gross_profit', 'margin']],
        'cashiers' => [['reports.cashier'], 'advanced_reports', []],
        'slow-moving' => [['reports.inventory'], 'advanced_reports', ['value']],
        'inventory-valuation' => [['reports.inventory'], null, ['unit_cost', 'value']],
        'low-stock' => [['reports.inventory'], null, []],
        'stock-movements' => [['reports.inventory'], null, ['unit_cost', 'total_cost']],
        'purchases' => [['reports.financial', 'po.view'], 'purchasing', []],
        'expenses' => [['reports.financial', 'expenses.view'], 'expenses', []],
        'payables' => [['reports.financial', 'supplier_payments.manage'], 'purchasing', []],
    ];

    public function __construct(
        private readonly ReportService $reports,
        private readonly PlanGate $plan,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Which reports this user can open (for the reports menu).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(collect(self::REPORTS)->map(fn ($def, $key) => [
            'key' => $key,
            'allowed' => $user->canAny($def[0]),
            'feature' => $def[1],
            'available' => $def[1] === null || $this->plan->hasFeature($def[1]),
        ])->values()->all(), 200, [
            'can_export' => $user->can('reports.export') && $this->plan->hasFeature('report_exports'),
            'can_see_costs' => $this->canSeeCosts($user),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse|Response
    {
        $def = self::REPORTS[$report] ?? throw new DomainException('Report not found.', 'NOT_FOUND', 404);
        $user = $this->user($request);
        if (! $user->canAny($def[0])) {
            throw new DomainException('You do not have permission to view this report.', 'FORBIDDEN', 403);
        }
        if ($def[1]) {
            $this->plan->assertFeature($def[1], 'This report');
        }

        $data = $request->validate([
            'format' => ['nullable', Rule::in(['json', 'csv', 'xlsx'])],
            'sort' => ['nullable', Rule::in(['net_sales', 'qty'])],
            'group' => ['nullable', Rule::in(['none', 'category'])],
        ]);
        $filters = $this->filters($request);

        $result = match ($report) {
            'sales' => $this->reports->sales($filters),
            'payments' => $this->reports->payments($filters),
            'discounts' => $this->reports->discounts($filters),
            'tax' => $this->reports->tax($filters),
            'sc-pwd' => $this->reports->scPwd($filters),
            'products' => $this->reports->products($filters, $data['sort'] ?? 'net_sales'),
            'fast-moving' => $this->reports->products($filters, 'qty'),
            'categories' => $this->reports->categories($filters),
            'branches' => $this->reports->branches($filters),
            'cashiers' => $this->reports->cashiers($filters),
            'slow-moving' => $this->reports->slowMoving($filters),
            'inventory-valuation' => $this->reports->inventoryValuation($filters),
            'low-stock' => $this->reports->lowStock($filters),
            'stock-movements' => $this->reports->stockMovements($filters),
            'purchases' => $this->reports->purchases($filters),
            'expenses' => $this->reports->expenses($filters, $data['group'] ?? 'none'),
            'payables' => $this->reports->payables($filters),
        };
        if (! $this->canSeeCosts($user)) {
            $result = $result->without($def[2]);
        }

        $format = $data['format'] ?? 'json';
        if ($format === 'json') {
            return ApiResponse::data($result->toArray($filters));
        }

        return $this->download($request, $result, $filters, $format);
    }

    /**
     * Home dashboard: today vs yesterday, this month, the last 30 days, top
     * products, payments today, alerts.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user->can('reports.sales')) {
            throw new DomainException('You do not have permission to view sales figures.', 'FORBIDDEN', 403);
        }
        $branches = $this->branches($request);
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $f = fn (CarbonImmutable $from, CarbonImmutable $to) => new ReportFilters($from, $to, $branches, $tz);
        $costs = $this->canSeeCosts($user);
        $strip = fn (array $k) => $costs ? $k : array_diff_key($k, array_flip(['cogs', 'gross_profit', 'margin', 'operating_profit', 'expenses']));

        $top = $this->plan->hasFeature('advanced_reports')
            ? array_slice($this->reports->products($f($today->startOfMonth(), $today))->without($costs ? [] : ['cogs', 'gross_profit', 'margin'])->rows, 0, 5)
            : null;

        return ApiResponse::data([
            'today' => $strip($this->reports->kpis($f($today, $today))),
            'yesterday' => $strip($this->reports->kpis($f($today->subDay(), $today->subDay()))),
            'month' => $strip($this->reports->kpis($f($today->startOfMonth(), $today))),
            'series' => $this->reports->dailySeries($f($today->subDays(29), $today)),
            'top_products' => $top,
            'payments_today' => $this->reports->payments($f($today, $today))->rows,
            'low_stock' => count($this->reports->lowStock($f($today, $today))->rows),
            'open_shifts' => CashRegisterSession::query()->whereIn('branch_id', $branches->pluck('id'))->where('status', 'open')->count(),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'can_see_costs' => $costs,
        ]);
    }

    private function filters(Request $request): ReportFilters
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
        ]);
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz);
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay() : $today->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay() : $today->startOfDay();
        $groupBy = $data['group_by'] ?? 'day';

        if ($groupBy === 'day' && $from->diffInDays($to) > 400) {
            throw new DomainException('Choose 400 days or fewer, or group by week, month or year.', 'RANGE_TOO_LONG', 422);
        }
        if ($from->diffInYears($to) > 10) {
            throw new DomainException('Choose a period of 10 years or less.', 'RANGE_TOO_LONG', 422);
        }

        return new ReportFilters($from, $to, $this->branches($request), $tz, $groupBy);
    }

    /**
     * `branch_id` = one branch, or "all" (every branch the user can see; the default).
     *
     * @return Collection<int, Branch>
     */
    private function branches(Request $request): Collection
    {
        $user = $this->user($request);
        $visible = Branch::query()
            ->when(! $user->canAccessAllBranches(), fn ($q) => $q->whereIn('id', $user->branches()->pluck('branches.id')))
            ->orderByDesc('is_main')->orderBy('name')->get();

        $wanted = $request->query('branch_id');
        if ($wanted === null || $wanted === '' || $wanted === 'all') {
            return $visible;
        }
        $branch = $visible->firstWhere('id', (int) $wanted);
        if (! $branch) {
            throw new DomainException('You do not have access to this branch.', 'BRANCH_FORBIDDEN', 403);
        }

        return collect([$branch]);
    }

    private function download(Request $request, Report $report, ReportFilters $filters, string $format): Response
    {
        $user = $this->user($request);
        if (! $user->can('reports.export')) {
            throw new DomainException('You do not have permission to export reports.', 'FORBIDDEN', 403);
        }
        $this->plan->assertFeature('report_exports', 'Report exports');

        $tenant = $this->tenant->requireTenant();
        $d = $filters->describe();
        $header = [
            "{$tenant->name} — {$report->title}",
            "Period: {$d['from']} to {$d['to']}",
            'Branches: '.implode(', ', array_column($d['branches'], 'name')),
        ];
        $rows = $report->rows;
        if ($report->totals) {
            $rows[] = $report->totals;
        }

        DB::table('report_exports')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'report' => $report->key, 'format' => $format,
            'filters' => json_encode($d), 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exporter = new TableExporter;
        $name = "{$report->key}-{$d['from']}-to-{$d['to']}.{$format}";
        if ($format === 'csv') {
            return response($exporter->csv($report->columns, $rows, $header), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}\"",
            ]);
        }

        return response($exporter->xlsx($report->columns, $rows, $header, $report->title), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }

    private function canSeeCosts(User $user): bool
    {
        return $user->can('reports.financial') || $user->can('inventory.valuation');
    }

    private function timezone(): string
    {
        return $this->tenant->tenant()?->timezone ?: 'Asia/Manila';
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
