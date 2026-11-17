<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'contact_person', 'phone', 'email', 'tin', 'vat_registered', 'address',
    'payment_terms_days', 'notes', 'is_active',
])]
class Supplier extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return ['vat_registered' => 'boolean', 'is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
