<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RefundItem extends Model
{
    use BelongsToTenant;

    public const RESTOCK = ['sellable', 'damaged', 'none'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund lines cannot be changed.'));
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
