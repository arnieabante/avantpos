<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'subscription_id', 'from_plan_id', 'to_plan_id', 'change_type', 'prorated_amount',
    'actor_id', 'actor_type', 'effective_at',
])]
class SubscriptionHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'subscription_history';

    protected function casts(): array
    {
        return [
            'prorated_amount' => 'decimal:2',
            'effective_at' => 'datetime',
        ];
    }
}
