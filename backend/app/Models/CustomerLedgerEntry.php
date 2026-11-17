<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a customer's account (amount owed). Append-only.
 * amount is signed: + increases what the customer owes.
 */
class CustomerLedgerEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPES = ['opening_balance', 'credit_sale', 'payment', 'refund', 'adjustment'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Customer ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Customer ledger entries are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
