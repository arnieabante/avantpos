<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoice line. Product name, SKU, unit and prices are copied at the
 * time of sale so the invoice never changes when the catalog does.
 */
class SaleItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:4',
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'qty_refunded' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_discount' => 'decimal:2',
            'allocated_transaction_discount' => 'decimal:2',
            'sc_pwd_discount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cogs' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
