<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Requests\Sales\SaleRequest;
use App\Http\Resources\Sales\RefundResource;
use App\Http\Resources\Sales\SaleResource;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Rules\TenantExists;
use App\Services\Audit\AuditLogger;
use App\Services\Sales\CheckoutService;
use App\Services\Sales\OfflineSyncService;
use App\Services\Sales\SaleReversalService;
use App\Support\Api\ApiResponse;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SaleController extends InventoryController
{
    private const RELATIONS = [
        'branch', 'terminal', 'cashier', 'customer', 'items', 'discounts.authorizer', 'payments.method', 'session', 'voider', 'voidAuthorizer',
        'refunds.items', 'refunds.terminal', 'refunds.processor', 'refunds.authorizer', 'refunds.payments.method',
    ];

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly SaleReversalService $reversals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Price a cart (no side effects). The POS shows these figures; they are
     * computed again at checkout.
     */
    public function quote(SaleRequest $request): JsonResponse
    {
        $q = $this->checkout->quote($this->branch(), $request->validated(), $this->actor($request));

        return ApiResponse::data([
            'lines' => array_map(fn ($l) => [
                'index' => $l['index'],
                'product_variant_id' => $l['variant']->getKey(),
                'product_unit_id' => $l['unit']?->getKey(),
                'name' => $l['variant']->name ? "{$l['product']->name} - {$l['variant']->name}" : $l['product']->name,
                'sku' => $l['variant']->sku,
                'unit' => $l['unit_label'],
                'qty' => (string) $l['qty']->toScale(3),
                'list_price' => (string) $l['list_price'],
                'unit_price' => (string) $l['unit_price'],
                'price_overridden' => $l['price_overridden'],
                'gross' => (string) $l['gross'],
                'line_discount' => (string) $l['line_discount'],
                'allocated_discount' => (string) $l['allocated_transaction_discount']->plus($l['allocated_loyalty']),
                'sc_pwd' => $l['sc_pwd'],
                'sc_pwd_discount' => (string) $l['sc_pwd_discount'],
                'tax_class' => $l['tax_class'],
                'vat_amount' => (string) $l['vat_amount'],
                'line_total' => (string) $l['line_total'],
                'sc_pwd_eligible' => (bool) $l['product']->sc_pwd_eligible,
            ], $q['lines']),
            'totals' => $q['totals'],
            'discounts' => array_map(fn ($d) => [
                'scope' => $d['scope'], 'line' => $d['line'], 'type' => $d['type'], 'name' => $d['name'],
                'value_type' => $d['value_type'], 'value' => $d['value'], 'amount' => (string) $d['amount'],
            ], $q['discounts']),
            'customer' => $q['customer'] ? [
                'id' => $q['customer']->id, 'name' => $q['customer']->name, 'loyalty_points' => $q['customer']->loyalty_points,
                'allow_credit' => $q['customer']->allow_credit,
                'available_credit' => $q['customer']->allow_credit
                    ? (string) Money::of($q['customer']->credit_limit)->minus($q['customer']->balance)->max('0') : null,
                'group' => $q['customer']->group?->name,
            ] : null,
            'points_redeemed' => $q['points_redeemed'],
            'vat_registered' => $q['vat_registered'],
            'approvals_required' => $q['approvals'],
            'approvals_missing' => $q['approvals_missing'],
        ]);
    }

    public function store(SaleRequest $request): JsonResponse
    {
        ['sale' => $sale, 'replayed' => $replayed] = $this->checkout->checkout($this->branch(), $request->validated(), $this->actor($request));

        return (new SaleResource($sale->load(self::RELATIONS)))->response()->setStatusCode($replayed ? 200 : 201);
    }

    /**
     * Sales history of the branch. Cashiers see their own sales only.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['completed', 'voided', 'partially_refunded', 'refunded'])],
            'sync_status' => ['nullable', Rule::in(['ok', 'flagged', 'reviewed'])],
            'terminal_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $actor = $this->actor($request);
        $term = $request->string('search')->trim()->toString();

        $sales = Sale::query()->with(['branch', 'terminal', 'cashier', 'customer', 'payments.method'])
            ->where('branch_id', $this->branch()->getKey())
            ->when(! $actor->can('sales.view_all'), fn ($q) => $q->where('cashier_id', $actor->getKey()))
            ->when($request->filled('cashier_id') && $actor->can('sales.view_all'), fn ($q) => $q->where('cashier_id', $request->integer('cashier_id')))
            ->when($request->filled('terminal_id'), fn ($q) => $q->where('terminal_id', $request->integer('terminal_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('sync_status'), fn ($q) => $q->where('sync_status', $request->input('sync_status')))
            ->when($request->filled('from'), fn ($q) => $q->where('sold_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('sold_at', '<=', $request->date('to')->endOfDay()))
            ->when($term !== '', fn (Builder $q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', $this->like($term))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $this->like($term)))))
            ->latest('sold_at')->latest('id')
            ->paginate($request->integer('per_page', 25));

        return SaleResource::collection($sales);
    }

    /**
     * Find a receipt by its invoice number, for a return (any cashier of the branch).
     */
    public function lookup(Request $request): AnonymousResourceCollection
    {
        $no = trim($request->validate(['invoice_no' => ['required', 'string', 'max:30']])['invoice_no']);
        // Invoice numbers are printed zero-padded; accept "15" for "000000015".
        $padded = ctype_digit($no) ? str_pad($no, 9, '0', STR_PAD_LEFT) : $no;

        $sales = Sale::query()->with(self::RELATIONS)
            ->where('branch_id', $this->branch()->getKey())
            ->whereIn('invoice_no', array_unique([$no, $padded]))
            ->latest('sold_at')->limit(10)->get();

        return SaleResource::collection($sales);
    }

    public function show(Request $request, Sale $sale): SaleResource
    {
        $this->assertVisible($request, $sale);

        return new SaleResource($sale->load(self::RELATIONS));
    }

    public function void(Request $request, Sale $sale): SaleResource
    {
        $this->assertBranchAccess($request, $sale->branch_id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'override_token' => ['nullable', 'string', 'max:100'],
        ]);

        $sale = $this->reversals->void($sale, $data['reason'], $data['override_token'] ?? null, $this->actor($request));

        return new SaleResource($sale->load(self::RELATIONS));
    }

    public function refund(Request $request, Sale $sale): JsonResponse
    {
        $this->assertBranchAccess($request, $sale->branch_id);
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            'items.*.restock' => ['required', Rule::in(RefundItem::RESTOCK)],
            'payment_method_id' => ['nullable', 'integer', TenantExists::on('payment_methods')],
            'override_token' => ['nullable', 'string', 'max:100'],
        ], ['items.required' => 'Choose the items being returned.']);

        ['refund' => $refund, 'replayed' => $replayed] = $this->reversals->refund($sale, $data, $this->actor($request));

        return ApiResponse::data([
            'refund' => (new RefundResource($refund->load(['items', 'terminal', 'processor', 'authorizer', 'payments.method'])))->toArray($request),
            'sale' => (new SaleResource($sale->fresh()->load(self::RELATIONS)))->toArray($request),
        ], $replayed ? 200 : 201);
    }

    /**
     * Reprints are logged (BIR: a reprint is marked as such on the receipt).
     */
    public function reprint(Request $request, Sale $sale): SaleResource
    {
        $this->assertVisible($request, $sale);
        $this->audit->log('sale.reprinted', $sale, meta: ['invoice_no' => $sale->invoice_no]);

        return new SaleResource($sale->load(self::RELATIONS));
    }

    /**
     * A manager confirms a flagged offline sale was checked.
     */
    public function reviewSync(Request $request, Sale $sale, OfflineSyncService $sync): SaleResource
    {
        $this->assertBranchAccess($request, $sale->branch_id);
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:500']]);

        return new SaleResource($sync->review($sale, $data['notes'], $this->actor($request))->load(self::RELATIONS));
    }

    private function assertVisible(Request $request, Sale $sale): void
    {
        $this->assertBranchAccess($request, $sale->branch_id);
        $actor = $this->actor($request);
        if (! $actor->can('sales.view_all') && $sale->cashier_id !== $actor->getKey()) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }
}
