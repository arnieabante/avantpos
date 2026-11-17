<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gapless number series. Only DocumentNumberService may modify it.
 */
#[Fillable(['scope_type', 'scope_id', 'doc_type', 'prefix', 'padding', 'next_number'])]
class DocumentSequence extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'padding' => 'integer',
            'next_number' => 'integer',
            'reset_counter' => 'integer',
        ];
    }
}
