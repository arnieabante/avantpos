<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Resources\ReceiptTemplateResource;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\DiscountPreset;
use App\Models\PaymentMethod;
use App\Models\PhBarangay;
use App\Models\PhCity;
use App\Models\PhProvince;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\ReceiptTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Billing\PlanGate;
use App\Services\Catalog\PriceResolver;
use App\Services\Customers\LoyaltyService;
use App\Services\Media\MediaStorage;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Sales\OfflineSyncService;
use App\Services\Sales\SaleCalculator;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only data for the selling screen: the set-up it loads once per shift
 * (business and BIR details for the receipt, payment methods, discounts,
 * categories, who can approve) and the item search / barcode scan.
 * Prices shown here are for display; the sale is always priced again by
 * SaleCalculator.
 */
class PosController extends InventoryController
{
    /** Permissions a supervisor can lend with their PIN at the POS. */
    public const APPROVALS = [
        'pos.price_override', 'pos.discount_line', 'pos.discount_transaction',
        'pos.discount_override_limit', 'pos.sc_pwd', 'pos.credit_sale', 'sales.void', 'sales.refund',
    ];

    public function __construct(
        private readonly PriceResolver $prices,
        private readonly PlanGate $plan,
        private readonly LoyaltyService $loyalty,
        private readonly MediaStorage $media,
        private readonly SaleCalculator $calculator,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function setup(Request $request, TenantContext $tenants): JsonResponse
    {
        $branch = $this->branch();
        $actor = $this->actor($request);
        /** @var Tenant $tenant */
        $tenant = $tenants->requireTenant();

        $session = CashRegisterSession::query()->with('terminal')
            ->where('user_id', $actor->getKey())->where('status', 'open')->first();
        $terminal = $session?->terminal;

        $credit = $this->plan->hasFeature('credit_sales');
        if ($credit) {
            PaymentMethod::chargeToAccount(); // created on first use, like the default methods
        }
        $methods = PaymentMethod::query()->where('is_active', true)
            ->when(! $credit, fn ($q) => $q->where('type', '!=', 'credit'))
            ->orderBy('sort_order')->orderBy('name')->get();

        $template = ReceiptTemplate::query()->where('is_default', true)->first()
            ?? ReceiptTemplate::query()->orderBy('id')->first();

        $loyalty = $this->loyalty->settings();
        $loyaltyOn = $loyalty['enabled'] && $this->plan->hasFeature('loyalty');

        return ApiResponse::data([
            'business' => [
                'name' => $tenant->name,
                'registered_name' => $tenant->registered_name,
                'business_style' => $tenant->business_style,
                'tin' => $tenant->tin,
                'vat_registered' => (bool) $tenant->vat_registered,
                'address' => $this->address($tenant->address_line, $tenant->barangay_code, $tenant->city_code, $tenant->province_code, $tenant->postal_code),
                'phone' => $tenant->phone,
                'logo_url' => $this->media->url($tenant->logo_path),
            ],
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'is_main' => (bool) $branch->is_main,
                'tin_branch_code' => $branch->bir_branch_code ?: $tenant->tin_branch_code,
                'address' => $this->address($branch->address_line, $branch->barangay_code, $branch->city_code, $branch->province_code, $branch->postal_code),
                'phone' => $branch->phone,
            ],
            'session' => $session && $session->branch_id === $branch->id ? ['id' => $session->id, 'opened_at' => $session->opened_at?->toIso8601String()] : null,
            'terminal' => $terminal ? [
                'id' => $terminal->id,
                'code' => $terminal->code,
                'name' => $terminal->name,
                'min' => $terminal->min,
                'serial_no' => $terminal->serial_no,
                'ptu_no' => $terminal->ptu_no,
                'ptu_issued_at' => $terminal->ptu_issued_at?->toDateString(),
                'ptu_valid_until' => $terminal->ptu_valid_until?->toDateString(),
            ] : null,
            'receipt' => $template
                ? (new ReceiptTemplateResource($template))->toArray($request)
                : ['id' => null, 'name' => 'Standard', 'paper' => '80mm', 'header_text' => null, 'footer_text' => null,
                    'show_logo' => false, 'layout' => ReceiptTemplate::LAYOUT_OPTIONS, 'is_default' => true],
            'payment_methods' => $methods->map(fn (PaymentMethod $m) => [
                'id' => $m->id, 'type' => $m->type, 'name' => $m->name, 'requires_reference' => (bool) $m->requires_reference,
            ])->values()->all(),
            'discount_presets' => DiscountPreset::query()->where('is_active', true)->whereNull('statutory')->orderBy('name')->get()
                ->map(fn (DiscountPreset $p) => [
                    'id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'value' => $p->value, 'scope' => $p->scope,
                    'requires_permission' => $p->requires_permission, 'max_amount' => $p->max_amount,
                ])->values()->all(),
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'color'])->map(fn ($c) => [
                    'id' => $c->id, 'parent_id' => $c->parent_id, 'name' => $c->name, 'color' => $c->color,
                ])->values()->all(),
            'loyalty' => $loyaltyOn ? [
                'spend_per_point' => $loyalty['spend_per_point'],
                'point_value' => $loyalty['point_value'],
                'min_redeem_points' => $loyalty['min_redeem_points'],
            ] : null,
            'features' => [
                'customers' => $this->plan->hasFeature('customers'),
                'credit_sales' => $credit,
                'loyalty' => $loyaltyOn,
            ],
            'max_discount_percent' => (string) config('avantpos.app.pos.max_discount_percent', '20'),
            // For selling offline: VAT rate (null = not VAT-registered) and the terminal's next invoice number.
            'vat_rate' => $this->calculator->vatRate() !== null ? (string) $this->calculator->vatRate() : null,
            'next_invoice_no' => $terminal ? $this->numbers->peek(DocumentType::SalesInvoice, $terminal->id) : null,
            'approvers' => $this->approvers($branch),
        ]);
    }

    /**
     * The whole sellable catalog of the branch, for selling offline. The
     * version changes when any price, unit, barcode or item changes (not
     * with stock); a client that already has it gets {unchanged: true}.
     */
    public function catalog(Request $request): JsonResponse
    {
        $items = $this->present($this->sellable()->orderBy('products.name')->orderBy('product_variants.name')->get());
        $version = md5((string) json_encode(array_map(fn ($i) => array_diff_key($i, ['qty_on_hand' => true]), $items)));

        if ($request->query('version') === $version) {
            return ApiResponse::data(['version' => $version, 'unchanged' => true]);
        }

        return ApiResponse::data(['version' => $version, 'unchanged' => false, 'generated_at' => now()->toIso8601String(), 'items' => $items]);
    }

    /**
     * Upload sales made offline (see OfflineSyncService).
     */
    public function sync(Request $request, OfflineSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'client_batch_uuid' => ['required', 'uuid'],
            'sales' => ['required', 'array', 'min:1', 'max:200'],
            'sales.*.client_uuid' => ['required', 'uuid'],
            'sales.*.invoice_no' => ['required', 'string', 'max:30'],
            'sales.*.sold_at' => ['required', 'date'],
            'sales.*.cash_register_session_id' => ['required', 'integer'],
            'sales.*.total' => ['nullable', 'numeric'],
            'sales.*.pricing_version' => ['nullable', 'string', 'max:40'],
            'sales.*.items' => ['required', 'array', 'min:1', 'max:200'],
            'sales.*.items.*.product_variant_id' => ['required', 'integer', TenantExists::on('product_variants')],
            'sales.*.items.*.product_unit_id' => ['nullable', 'integer', TenantExists::on('product_units')],
            'sales.*.items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'sales.*.items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'sales.*.payments' => ['required', 'array', 'min:1', 'max:10'],
            'sales.*.payments.*.payment_method_id' => ['required', 'integer', TenantExists::on('payment_methods')],
            'sales.*.payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'sales.*.payments.*.reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        return ApiResponse::data($sync->sync($this->branch(), $data, $this->actor($request)));
    }

    /**
     * Search (name, SKU, barcode) or browse a category. Active, sellable items
     * with the branch price, selling units and stock on hand.
     */
    public function items(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $term = $request->string('q')->trim()->toString();
        $category = $request->integer('category_id') ?: null;

        $variants = $this->sellable()
            ->when($category, fn ($q) => $q->whereIn('products.category_id', ProductCategory::query()
                ->where('id', $category)->orWhere('parent_id', $category)->select('id')))
            ->when($term !== '', function ($q) use ($term) {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('products.name', 'like', $like)
                    ->orWhere('product_variants.name', 'like', $like)
                    ->orWhere('product_variants.sku', 'like', $like)
                    ->orWhereIn('product_variants.id', ProductBarcode::query()->select('product_variant_id')->where('barcode', $term)));
            })
            ->orderBy('products.name')->orderBy('product_variants.name')
            ->limit(60)
            ->get();

        return ApiResponse::data($this->present($variants));
    }

    /**
     * Exact barcode or SKU, as typed by a scanner. A pack/box barcode returns
     * the item in that unit.
     */
    public function scan(Request $request): JsonResponse
    {
        $code = trim($request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        $barcode = ProductBarcode::query()->where('barcode', $code)->first();
        $variantId = $barcode?->product_variant_id
            ?? ProductVariant::query()->where('sku', $code)->value('id');

        $variant = $variantId ? $this->sellable()->where('product_variants.id', $variantId)->first() : null;
        if (! $variant) {
            throw new DomainException("No item for sale has the code {$code}.", 'PRODUCT_NOT_FOUND', 404);
        }

        $item = $this->present(new Collection([$variant]))[0];
        $unitId = $barcode?->product_unit_id;
        $item['scanned_unit_id'] = $unitId && collect($item['units'])->contains('id', $unitId) ? $unitId : null;

        return ApiResponse::data($item);
    }

    /**
     * @return Builder<ProductVariant>
     */
    private function sellable(): Builder
    {
        $branchId = $this->branch()->getKey();

        return ProductVariant::query()
            ->with(['product.baseUnit', 'product.category', 'barcodes', 'units' => fn ($q) => $q->where('is_sellable', true)->with('unit')])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'product_variants.id')->where('b.branch_id', $branchId))
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('product_variants.is_active', true)
            ->select('product_variants.*')
            ->selectRaw('COALESCE(b.qty_on_hand, 0) AS stock_qty');
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array<string, mixed>>
     */
    private function present(Collection $variants): array
    {
        $branchId = $this->branch()->getKey();
        $this->prices->preload($branchId, $variants->pluck('id'));

        return $variants->map(function (ProductVariant $v) use ($branchId) {
            $product = $v->product;
            $base = $this->prices->resolve($v, $branchId);
            $units = collect([[
                'id' => null,
                'name' => $product->baseUnit->name,
                'abbreviation' => $product->baseUnit->abbreviation,
                'conversion_factor' => '1.0000',
                'allows_decimal' => (bool) $product->baseUnit->allows_decimal,
                'price' => $base['selling_price'],
            ]])->merge($v->units->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'name' => $u->unit->name,
                'abbreviation' => $u->unit->abbreviation,
                'conversion_factor' => $u->conversion_factor,
                'allows_decimal' => (bool) $u->unit->allows_decimal,
                'price' => $this->prices->resolve($v, $branchId, $u)['selling_price'],
            ]));

            return [
                'product_variant_id' => $v->id,
                'product_id' => $product->id,
                'name' => $v->name ? "{$product->name} - {$v->name}" : $product->name,
                'sku' => $v->sku,
                'category_id' => $product->category_id,
                'color' => $product->category?->color,
                'image_url' => $this->media->url($product->image_path),
                'type' => $product->type,
                'tax_class' => $product->tax_class,
                'sc_pwd_eligible' => (bool) $product->sc_pwd_eligible,
                'track_inventory' => $product->type === 'standard' && $product->track_inventory,
                'allow_negative_stock' => (bool) $product->allow_negative_stock,
                'qty_on_hand' => (string) BigDecimal::of($v->getAttribute('stock_qty') ?? '0')->toScale(3),
                'price' => $base['selling_price'],
                'units' => $units->values()->all(),
                'barcodes' => $v->barcodes->map(fn ($b) => ['code' => $b->barcode, 'unit_id' => $b->product_unit_id])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Users who can approve at this branch with their PIN, and what they can approve.
     *
     * @return list<array{id: int, name: string, permissions: list<string>}>
     */
    private function approvers(Branch $branch): array
    {
        return User::query()->whereNotNull('pin_hash')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->isActive() && $u->canAccessBranch($branch))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'permissions' => array_values(array_filter(self::APPROVALS, fn ($p) => $u->hasPermissionTo($p))),
            ])
            ->filter(fn ($u) => $u['permissions'] !== [])
            ->values()->all();
    }

    private function address(?string $line, ?string $barangay, ?string $city, ?string $province, ?string $postal): ?string
    {
        $parts = array_filter([
            $line,
            $barangay ? PhBarangay::query()->whereKey($barangay)->value('name') : null,
            $city ? PhCity::query()->whereKey($city)->value('name') : null,
            $province ? PhProvince::query()->whereKey($province)->value('name') : null,
        ]);
        $text = implode(', ', $parts);
        if ($postal) {
            $text = trim("{$text} {$postal}");
        }

        return $text !== '' ? $text : null;
    }
}
