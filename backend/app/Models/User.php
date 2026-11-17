<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'status'])]
#[Hidden(['password', 'remember_token', 'pin_hash'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('tenant_id')->withTimestamps();
    }

    /**
     * Replace the user's branch assignments (pivot rows carry tenant_id).
     *
     * @param  array<int>  $branchIds  Must already be validated as belonging to the tenant.
     */
    public function syncBranches(array $branchIds): void
    {
        $this->branches()->sync(
            collect($branchIds)->unique()->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $this->tenant_id]])->all()
        );
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->tenant()->value('owner_user_id') === $this->getKey();
    }

    public function canAccessAllBranches(): bool
    {
        return $this->can('branches.access_all');
    }

    public function canAccessBranch(Branch|int $branch): bool
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $this->canAccessAllBranches()
            || $this->branches()->whereKey($branchId)->exists();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function setPin(string $pin): void
    {
        $this->forceFill(['pin_hash' => Hash::make($pin)])->save();
    }

    public function checkPin(string $pin): bool
    {
        $hash = $this->getAttributes()['pin_hash'] ?? null;

        return $hash !== null && Hash::check($pin, $hash);
    }

    public function hasPin(): bool
    {
        return ($this->getAttributes()['pin_hash'] ?? null) !== null;
    }
}
