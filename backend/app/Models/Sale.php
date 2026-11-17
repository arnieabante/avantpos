<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A completed POS sale (sales invoice). Amounts are VAT-inclusive unless the
 * column says otherwise. Never deleted: voids and refunds are recorded on it.
 */
class Sale extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Sales are never deleted; void or refund them.'));
    }

    protected function casts(): array
    {
        $money = array_fill_keys([
            'gross_amount', 'line_discount_total', 'transaction_discount_total', 'sc_pwd_discount_total', 'loyalty_discount',
            'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'zero_rated_sales', 'total_amount', 'amount_paid',
            'change_due', 'cogs_total',
        ], 'decimal:2');

        return $money + [
            'points_redeemed' => 'decimal:2',
            'points_earned' => 'decimal:2',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
            'sync_issues' => 'array',
        ];
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<SaleDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(SaleDiscount::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return BelongsTo<CashRegisterSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_authorized_by');
    }

    /** Statuses that can still be refunded. */
    public function isRefundable(): bool
    {
        return in_array($this->status, ['completed', 'partially_refunded'], true);
    }
}
