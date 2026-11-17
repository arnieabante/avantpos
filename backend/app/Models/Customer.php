<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer of the business.
 *
 * - sc_pwd_id_no is encrypted at rest (Data Privacy Act) and only ever shown masked
 *   outside the customer form.
 * - balance (amount owed) and loyalty_points are caches of their append-only ledgers,
 *   written only by CustomerAccountService / LoyaltyService under a row lock.
 */
#[Fillable([
    'customer_group_id', 'code', 'name', 'phone', 'email', 'tin', 'address', 'birthdate',
    'sc_pwd_id_type', 'sc_pwd_id_no', 'allow_credit', 'credit_limit', 'notes', 'is_active',
])]
class Customer extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const SC_PWD_TYPES = ['senior', 'pwd'];

    protected $hidden = ['sc_pwd_id_no'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date:Y-m-d',
            'sc_pwd_id_no' => 'encrypted',
            'loyalty_points' => 'decimal:2',
            'allow_credit' => 'boolean',
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * "••••5678": enough for staff to match an ID card, nothing more (not even the length).
     */
    public function maskedScPwdId(): ?string
    {
        $id = $this->sc_pwd_id_no;
        if ($id === null || $id === '') {
            return null;
        }

        return '••••'.mb_substr($id, -4);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return HasMany<CustomerLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    /**
     * @return HasMany<LoyaltyTransaction, $this>
     */
    public function loyalty(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
