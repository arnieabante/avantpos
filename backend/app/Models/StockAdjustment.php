<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manual stock correction (or opening stock) for one branch.
 * draft -> posted | cancelled. A posted adjustment is final.
 */
class StockAdjustment extends Model
{
    use BelongsToTenant;

    /** Reasons that always add stock / always remove stock. "correction" and "other" may go either way. */
    public const IN_REASONS = ['opening', 'found'];

    public const OUT_REASONS = ['damaged', 'expired', 'lost', 'internal_use'];

    public const REASONS = ['opening', 'found', 'damaged', 'expired', 'lost', 'internal_use', 'correction', 'other'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
