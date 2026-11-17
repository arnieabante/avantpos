<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'is_active'])]
class ExpenseCategory extends Model
{
    use BelongsToTenant;

    /** Created for every business (and for older ones the first time expenses are opened). */
    public const DEFAULTS = [
        'Rent', 'Electricity', 'Water', 'Internet and phone', 'Salaries and wages', 'Supplies', 'Transportation and delivery',
        'Repairs and maintenance', 'Taxes, permits and licenses', 'Bank charges', 'Marketing', 'Others',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
