<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A product. Every product has at least one variant (a simple product has
 * exactly one, the default variant); stock, sales and purchasing always
 * reference a variant. Quantities are kept in the product's base unit.
 */
#[Fillable([
    'category_id', 'brand_id', 'base_unit_id', 'name', 'description', 'type', 'tax_class',
    'sc_pwd_eligible', 'track_inventory', 'track_expiry', 'allow_negative_stock', 'meta', 'is_active',
])]
class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = ['standard', 'service'];

    public const TAX_CLASSES = ['vatable', 'vat_exempt', 'zero_rated'];

    /** Vertical-specific extra fields (e.g. pharmacy) allowed in `meta`. */
    public const META_FIELDS = ['generic_name' => 'string', 'requires_prescription' => 'boolean'];

    protected function casts(): array
    {
        return [
            'sc_pwd_eligible' => 'boolean',
            'track_inventory' => 'boolean',
            'track_expiry' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }
}
