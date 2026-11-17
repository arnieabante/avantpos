<?php

namespace App\Models;

use App\Enums\TerminalStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TerminalFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A POS device. A registered terminal holds a device-bound Sanctum token
 * (abilities: terminal:*) used for offline sync; it can never act as a user.
 */
#[Fillable([
    'branch_id', 'code', 'name', 'status',
    'min', 'serial_no', 'ptu_no', 'ptu_issued_at', 'ptu_valid_until',
    'device_label', 'device_platform',
])]
class Terminal extends Model implements AuthenticatableContract
{
    /** @use HasFactory<TerminalFactory> */
    use Authenticatable, BelongsToTenant, HasApiTokens, HasFactory;

    public const ABILITY_SYNC = 'terminal:sync';

    protected function casts(): array
    {
        return [
            'status' => TerminalStatus::class,
            'ptu_issued_at' => 'date',
            'ptu_valid_until' => 'date',
            'device_registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<CashRegister, $this>
     */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function isActive(): bool
    {
        return $this->status === TerminalStatus::Active;
    }

    /** Terminals have no password. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
