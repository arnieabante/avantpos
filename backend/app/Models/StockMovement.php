<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One line of the append-only stock ledger. Never updated or deleted:
 * mistakes are corrected with a new, opposite movement.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPES = [
        'opening', 'purchase_receipt', 'sale', 'sale_void', 'return', 'adjustment_in', 'adjustment_out',
        'transfer_out', 'transfer_in', 'count_variance', 'receipt_reversal',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'balance_after' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Layers this movement consumed (outbound) — the exact cost trail.
     *
     * @return HasMany<StockMovementLayer, $this>
     */
    public function layers(): HasMany
    {
        return $this->hasMany(StockMovementLayer::class);
    }
}
