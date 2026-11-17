<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Tenancy\Scopes\TenantScope;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subscribing business. NOT tenant-scoped itself (it is the tenant).
 */
#[Fillable([
    'name', 'slug', 'subdomain', 'status', 'business_type',
    'registered_name', 'business_style', 'tin', 'tin_branch_code', 'rdo_code', 'vat_registered',
    'email', 'phone', 'address_line', 'region_code', 'province_code', 'city_code', 'barangay_code', 'postal_code',
    'timezone', 'currency', 'locale', 'trial_ends_at',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'vat_registered' => 'boolean',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScope(TenantScope::class)->latestOfMany();
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScope(TenantScope::class);
    }

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }

    /**
     * Full host of the tenant's subdomain, e.g. "arnelmotoshop.avantpos.ph".
     */
    public function host(): ?string
    {
        return $this->subdomain
            ? $this->subdomain.'.'.config('avantpos.tenancy.base_domain')
            : null;
    }
}
