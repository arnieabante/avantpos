<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * system_qty is the on-hand quantity at the moment the item was counted, so
 * the variance (counted - system) stays correct even if sales happen while
 * the rest of the store is still being counted.
 */
class StockCountItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['system_qty' => 'decimal:3', 'counted_qty' => 'decimal:3', 'variance_qty' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
