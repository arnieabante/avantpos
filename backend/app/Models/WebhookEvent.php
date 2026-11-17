<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payment-gateway webhook as received (platform-wide, not tenant data).
 * Unique per gateway + event id, so a re-delivered event is processed once.
 */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
