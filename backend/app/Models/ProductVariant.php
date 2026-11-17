<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable item: the default variant of a simple product, or one
 * size/colour of a product with variants. Prices are DECIMAL strings.
 *
 * cost_price is a reference (last purchase cost); real COGS comes from the
 * FIFO inventory layers (Phase 4D).
 */
#[Fillable([
    'product_id', 'name', 'sku', 'attributes', 'cost_price', 'selling_price', 'wholesale_price',
    'reorder_level', 'is_default', 'is_active',
])]
class ProductVariant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Extra selling / buying units (pack, box...). The base unit is implicit.
     *
     * @return HasMany<ProductUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('conversion_factor');
    }

    /**
     * @return HasMany<ProductBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class)->orderBy('id');
    }

    /**
     * @return HasMany<BranchProductPrice, $this>
     */
    public function branchPrices(): HasMany
    {
        return $this->hasMany(BranchProductPrice::class);
    }
}
