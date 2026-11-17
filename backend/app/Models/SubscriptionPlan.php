<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level plan (not tenant-scoped). Managed by the SaaS administrator.
 */
#[Fillable([
    'code', 'name', 'description', 'monthly_price', 'annual_price', 'trial_days', 'grace_days',
    'max_branches', 'max_users', 'max_terminals', 'max_products', 'is_public', 'is_active', 'sort_order',
])]
class SubscriptionPlan extends Model
{
    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * @return array<string, bool>
     */
    public function featureMap(): array
    {
        return $this->features->mapWithKeys(fn (PlanFeature $f) => [$f->feature_key => $f->enabled])->all();
    }

    public function hasFeature(string $key): bool
    {
        return (bool) ($this->featureMap()[$key] ?? false);
    }

    /**
     * @return array<string, int|null>
     */
    public function limits(): array
    {
        return [
            'branches' => $this->max_branches,
            'users' => $this->max_users,
            'terminals' => $this->max_terminals,
            'products' => $this->max_products,
        ];
    }

    /** A plan that costs nothing in either billing cycle. */
    public function isFree(): bool
    {
        return BigDecimal::of($this->monthly_price ?? '0')->isZero() && BigDecimal::of($this->annual_price ?? '0')->isZero();
    }

    /** Price of one period of the cycle (VAT-inclusive). */
    public function price(string $cycle): string
    {
        return (string) ($cycle === 'annual' ? $this->annual_price : $this->monthly_price);
    }
}
