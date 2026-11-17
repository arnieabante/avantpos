<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A FIFO cost layer: stock that came in together at one unit cost (and,
 * for batch-tracked products, one batch / expiry date).
 */
class InventoryLayer extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
            'qty_received' => 'decimal:3',
            'qty_remaining' => 'decimal:3',
            'expiry_date' => 'date:Y-m-d',
            'received_at' => 'datetime',
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
