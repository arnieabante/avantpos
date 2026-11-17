<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * X reading (mid-day / per shift) or Z reading (end of day) of a terminal.
 * The accumulated grand total is non-resettable (BIR). Never edited or deleted.
 */
class RegisterReading extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Register readings cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Register readings are never deleted.'));
    }

    protected function casts(): array
    {
        $money = array_fill_keys([
            'gross_sales', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'zero_rated_sales', 'sc_discount', 'pwd_discount',
            'other_discount', 'void_amount', 'refund_amount', 'net_sales', 'accumulated_grand_total_before', 'accumulated_grand_total_after',
        ], 'decimal:2');

        return $money + [
            'business_date' => 'date',
            'generated_at' => 'datetime',
            'payment_breakdown' => 'array',
            'reading_no' => 'integer',
        ];
    }

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @return BelongsTo<CashRegisterSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
