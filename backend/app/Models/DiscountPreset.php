<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable discount. Statutory presets (senior, PWD, ...) have their rate and
 * scope fixed by law and cannot be changed by the tenant.
 */
#[Fillable(['name', 'type', 'value', 'scope', 'statutory', 'requires_permission', 'max_amount', 'is_active'])]
class DiscountPreset extends Model
{
    use BelongsToTenant;

    public const TYPES = ['percent', 'fixed'];

    public const SCOPES = ['line', 'transaction'];

    public const STATUTORY = ['senior', 'pwd', 'naac', 'solo_parent'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'max_amount' => 'decimal:2',
            'requires_permission' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isStatutory(): bool
    {
        return $this->statutory !== null;
    }
}
