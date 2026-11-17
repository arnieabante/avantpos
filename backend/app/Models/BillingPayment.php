<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A payment for a subscription invoice: confirmed by the gateway (webhook)
 * or, for bank transfers, submitted by the business and verified by an
 * AvantPOS admin. pending → paid | failed. Never deleted.
 */
class BillingPayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Billing payments are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'verified_by');
    }
}
