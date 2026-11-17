<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * VAT / percentage-tax rate (percent, 4 decimals). Exactly one active rate is the default.
 */
#[Fillable(['name', 'type', 'rate', 'is_default', 'is_active'])]
class TaxRate extends Model
{
    use BelongsToTenant;

    public const TYPES = ['vat', 'percentage_tax'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
