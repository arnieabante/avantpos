<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An alternative unit of a variant: e.g. "Pack of 10" = 10 base units,
 * with its own price and barcode (sari-sari "tingi", hardware box/meter).
 */
#[Fillable(['product_variant_id', 'unit_id', 'conversion_factor', 'selling_price', 'wholesale_price', 'is_sellable', 'is_purchasable'])]
class ProductUnit extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<ProductBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class)->orderBy('id');
    }
}
