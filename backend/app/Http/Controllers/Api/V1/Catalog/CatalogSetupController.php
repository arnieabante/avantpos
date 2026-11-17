<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BrandRequest;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Requests\Catalog\UnitRequest;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\UnitResource;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Services\Catalog\CatalogSetupService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Categories, brands and units (small lists: returned unpaginated).
 */
class CatalogSetupController extends Controller
{
    public function __construct(private readonly CatalogSetupService $catalog) {}

    /* ---------------------------------------------------------------- Categories */

    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            ProductCategory::query()->withCount(['products', 'children'])->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function storeCategory(CategoryRequest $request): JsonResponse
    {
        return (new CategoryResource($this->catalog->createCategory($request->validated())))->response()->setStatusCode(201);
    }

    public function updateCategory(CategoryRequest $request, ProductCategory $category): CategoryResource
    {
        return new CategoryResource($this->catalog->update('category.updated', $category, $request->validated()));
    }

    public function destroyCategory(ProductCategory $category): JsonResponse
    {
        $this->catalog->deleteCategory($category);

        return ApiResponse::noContent();
    }

    /* ------------------------------------------------------------------- Brands */

    public function brands(): AnonymousResourceCollection
    {
        return BrandResource::collection(Brand::query()->withCount('products')->orderBy('name')->get());
    }

    public function storeBrand(BrandRequest $request): JsonResponse
    {
        return (new BrandResource($this->catalog->createBrand($request->string('name')->trim()->toString())))->response()->setStatusCode(201);
    }

    public function updateBrand(BrandRequest $request, Brand $brand): BrandResource
    {
        return new BrandResource($this->catalog->update('brand.updated', $brand, $request->validated()));
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        $this->catalog->deleteBrand($brand);

        return ApiResponse::noContent();
    }

    /* -------------------------------------------------------------------- Units */

    public function units(): AnonymousResourceCollection
    {
        return UnitResource::collection(Unit::query()->orderBy('name')->get());
    }

    public function storeUnit(UnitRequest $request): JsonResponse
    {
        return (new UnitResource($this->catalog->createUnit($request->validated())))->response()->setStatusCode(201);
    }

    public function updateUnit(UnitRequest $request, Unit $unit): UnitResource
    {
        return new UnitResource($this->catalog->update('unit.updated', $unit, $request->validated()));
    }

    public function destroyUnit(Unit $unit): JsonResponse
    {
        $this->catalog->deleteUnit($unit);

        return ApiResponse::noContent();
    }
}
