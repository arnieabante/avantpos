<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A receipt or document attached to an expense (stored privately).
 */
class ExpenseAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];
}
