<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing group: price tier (retail / wholesale prices) and a standard discount.
 */
#[Fillable(['name', 'price_tier', 'discount_percent'])]
class CustomerGroup extends Model
{
    use BelongsToTenant;

    public const PRICE_TIERS = ['retail', 'wholesale'];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:4'];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
