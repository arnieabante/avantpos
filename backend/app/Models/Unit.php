<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit of measure (piece, pack, kg, meter...). `allows_decimal` permits
 * fractional quantities (weighed / metered items).
 */
#[Fillable(['name', 'abbreviation', 'allows_decimal'])]
class Unit extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['allows_decimal' => 'boolean'];
    }
}
