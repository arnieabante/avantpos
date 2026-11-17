<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A way customers can pay. Non-cash methods normally require a reference
 * number; they are never marked successful without verification (Phase 5).
 */
#[Fillable(['type', 'name', 'requires_reference', 'is_active', 'sort_order'])]
class PaymentMethod extends Model
{
    use BelongsToTenant;

    public const TYPES = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'credit', 'other'];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The "Charge to account" method used for credit (utang) sales. Created
     * the first time it is needed so existing businesses get it too.
     */
    public static function chargeToAccount(): self
    {
        return static::query()->where('type', 'credit')->orderBy('id')->first()
            ?? static::query()->create([
                'type' => 'credit', 'name' => 'Charge to account', 'requires_reference' => false, 'is_active' => true, 'sort_order' => 90,
            ]);
    }
}
