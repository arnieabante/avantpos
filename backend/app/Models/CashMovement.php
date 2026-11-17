<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Cash put into or taken out of a drawer outside a sale:
 *  cash_in  — e.g. extra change from the safe
 *  cash_out — e.g. cash drop to the safe / bank
 *  payout   — e.g. paying a delivery or buying supplies from the till
 * Append-only.
 */
class CashMovement extends Model
{
    use BelongsToTenant;

    public const TYPES = ['cash_in', 'cash_out', 'payout'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Cash movements are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
