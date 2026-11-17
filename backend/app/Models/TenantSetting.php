<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class TenantSetting extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
