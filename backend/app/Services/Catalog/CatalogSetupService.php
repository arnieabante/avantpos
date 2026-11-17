<?php

namespace App\Services\Catalog;

use App\Exceptions\DomainException;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories, brands and units. Records still used by products cannot be
 * deleted (deactivate the category, or reassign the products, first).
 */
class CatalogSetupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): ProductCategory
    {
        $category = ProductCategory::query()->create($data + ['is_active' => true]);
        $this->audit->log('category.created', $category, new: $category->only(['name', 'parent_id']));

        return $category;
    }

    public function deleteCategory(ProductCategory $category): void
    {
        if ($category->children()->exists()) {
            throw new DomainException('Delete or move its subcategories first.', 'CATEGORY_HAS_CHILDREN', 422);
        }

        $this->assertUnused(Product::query()->where('category_id', $category->id)->exists(), 'category');
        $this->deleted('category.deleted', $category);
    }

    /**
     * Re-creating a deleted brand restores it (brand names are unique per business).
     */
    public function createBrand(string $name): Brand
    {
        $trashed = Brand::onlyTrashed()->where('name', $name)->first();

        if ($trashed) {
            $trashed->restore();
            $this->audit->log('brand.restored', $trashed);

            return $trashed;
        }

        $brand = Brand::query()->create(['name' => $name]);
        $this->audit->log('brand.created', $brand, new: ['name' => $name]);

        return $brand;
    }

    public function deleteBrand(Brand $brand): void
    {
        $this->assertUnused(Product::query()->where('brand_id', $brand->id)->exists(), 'brand');
        $this->deleted('brand.deleted', $brand);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createUnit(array $data): Unit
    {
        $unit = Unit::query()->create($data);
        $this->audit->log('unit.created', $unit, new: $unit->only(['name', 'abbreviation']));

        return $unit;
    }

    public function deleteUnit(Unit $unit): void
    {
        $used = Product::withTrashed()->where('base_unit_id', $unit->id)->exists()
            || ProductUnit::query()->where('unit_id', $unit->id)->exists();

        $this->assertUnused($used, 'unit');
        $this->deleted('unit.deleted', $unit);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $event, Model $model, array $data): Model
    {
        $model->fill($data)->save();
        $this->audit->logChanges($event, $model);

        return $model;
    }

    private function assertUnused(bool $used, string $what): void
    {
        if ($used) {
            throw new DomainException("This {$what} is used by products. Change those products first.", strtoupper($what).'_IN_USE', 422);
        }
    }

    private function deleted(string $event, Model $model): void
    {
        $this->audit->log($event, $model, old: ['name' => $model->getAttribute('name')]);
        $model->delete();
    }
}
