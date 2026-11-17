<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * How much of which cost layer an outbound movement consumed.
 */
class StockMovementLayer extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movement layers are append-only.'));
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<InventoryLayer, $this>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }
}
