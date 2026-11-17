<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Rules\TenantExists;
use App\Services\Catalog\PriceResolver;
use App\Services\Catalog\ProductService;
use App\Services\Media\MediaStorage;
use App\Support\Api\ApiResponse;
use App\Tenancy\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\File;

class ProductController extends Controller
{
    private const DETAIL_RELATIONS = ['category', 'brand', 'baseUnit', 'variants.units.unit', 'variants.barcodes', 'variants.branchPrices'];

    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', TenantExists::on('product_categories')],
            'brand_id' => ['nullable', 'integer', TenantExists::on('brands')],
            'status' => ['nullable', 'in:active,inactive'],
            'type' => ['nullable', 'in:standard,service'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['category', 'brand', 'baseUnit', 'variants'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = $request->string('search')->trim()->toString();
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $raw).'%';
                $q->where(fn ($w) => $w
                    ->where('name', 'like', $term)
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                    ->orWhereHas('variants.barcodes', fn ($b) => $b->where('barcode', $raw)));
            })
            // A top-level category also matches its subcategories.
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $id = $request->integer('category_id');
                $ids = ProductCategory::query()->where('parent_id', $id)->pluck('id')->push($id);
                $q->whereIn('category_id', $ids);
            })
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ProductListResource::collection($products);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated());

        return (new ProductResource($product->load(self::DETAIL_RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(self::DETAIL_RELATIONS));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $product = $this->products->update($request->user(), $product, $request->validated());

        return new ProductResource($product->load(self::DETAIL_RELATIONS));
    }

    public function activate(Product $product): ProductResource
    {
        return new ProductResource($this->products->setActive($product, true)->load(self::DETAIL_RELATIONS));
    }

    public function deactivate(Product $product): ProductResource
    {
        return new ProductResource($this->products->setActive($product, false)->load(self::DETAIL_RELATIONS));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->products->delete($product);

        return ApiResponse::noContent();
    }

    public function uploadImage(Request $request, Product $product): ProductResource
    {
        $request->validate([
            'image' => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(4 * 1024)],
        ]);

        return new ProductResource($this->products->replaceImage($product, $request->file('image'))->load(self::DETAIL_RELATIONS));
    }

    public function deleteImage(Product $product): ProductResource
    {
        return new ProductResource($this->products->removeImage($product)->load(self::DETAIL_RELATIONS));
    }

    /**
     * Replace the branch prices of one variant (prices.manage).
     */
    public function branchPrices(Request $request, ProductVariant $productVariant): JsonResponse
    {
        $data = $request->validate([
            'prices' => ['present', 'array', 'max:200'],
            'prices.*.branch_id' => ['required', 'integer', 'distinct', TenantExists::on('branches')],
            'prices.*.selling_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'prices.*.wholesale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ]);

        $variant = $this->products->setBranchPrices($productVariant, $data['prices']);

        return ApiResponse::data(ProductResource::variant(
            $variant->load(['units.unit', 'barcodes', 'branchPrices']),
            (bool) $request->user()->can('products.view_cost'),
        ));
    }

    /**
     * Find a sellable item by barcode (base or selling unit) or SKU, with the
     * price that applies at the current branch (X-Branch-Id).
     */
    public function lookup(Request $request, PriceResolver $prices, BranchContext $branch): JsonResponse
    {
        $code = trim($request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        $barcode = ProductBarcode::query()->where('barcode', $code)->with(['variant', 'productUnit.unit'])->first();
        $variant = $barcode?->variant ?? ProductVariant::query()->where('sku', $code)->first();
        $unit = $barcode?->productUnit;

        $product = $variant?->product;
        if (! $variant || ! $variant->is_active || ! $product || $product->trashed() || ! $product->is_active) {
            throw new DomainException("No active product has the code {$code}.", 'PRODUCT_NOT_FOUND', 404);
        }

        $product->loadMissing('baseUnit');

        return ApiResponse::data([
            'matched_by' => $barcode ? 'barcode' : 'sku',
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'tax_class' => $product->tax_class,
                'sc_pwd_eligible' => $product->sc_pwd_eligible,
                'track_inventory' => $product->track_inventory,
                'image_url' => app(MediaStorage::class)->url($product->image_path),
                'base_unit' => ['name' => $product->baseUnit->name, 'abbreviation' => $product->baseUnit->abbreviation, 'allows_decimal' => $product->baseUnit->allows_decimal],
            ],
            'variant' => ['id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku],
            'unit' => $unit ? [
                'id' => $unit->id,
                'name' => $unit->unit->name,
                'abbreviation' => $unit->unit->abbreviation,
                'conversion_factor' => $unit->conversion_factor,
            ] : null,
            'price' => $prices->resolve($variant, $branch->id(), $unit),
        ]);
    }
}
