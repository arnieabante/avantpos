<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A cashier's shift on one terminal and drawer: opening float, cash
 * movements, the sales paid into it, and the count at closing.
 * At most one open session per terminal (database unique index) and per drawer.
 */
class CashRegisterSession extends Model
{
    use BelongsToTenant;

    /** Philippine bills and coins accepted in a drawer count (value => label). */
    public const DENOMINATIONS = [
        '1000' => '₱1,000', '500' => '₱500', '200' => '₱200', '100' => '₱100', '50' => '₱50', '20' => '₱20 bill',
        '20c' => '₱20 coin', '10' => '₱10', '5' => '₱5', '1' => '₱1', '0.25' => '25¢', '0.05' => '5¢', '0.01' => '1¢',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // A closed session is a financial record: it is never changed again.
        static::updating(function (self $session) {
            if ($session->getOriginal('status') === 'closed') {
                throw new LogicException('A closed register session cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Register sessions are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'denominations' => 'array',
        ];
    }

    /** Cash value of a denomination key ("20c" is the ₱20 coin). */
    public static function denominationValue(string $key): string
    {
        return rtrim($key, 'c');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
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
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
