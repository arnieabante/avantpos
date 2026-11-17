<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a customer's points. Append-only; points are signed.
 */
class LoyaltyTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Loyalty transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Loyalty transactions are append-only.'));
    }

    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
