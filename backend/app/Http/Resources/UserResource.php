<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * A user of the business (user management screens).
 *
 * @property User $resource
 */
class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        /** @var Role|null $role */
        $role = $user->relationLoaded('roles') ? $user->roles->first() : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status->value,
            'email_verified' => $user->hasVerifiedEmail(),
            'has_pin' => $user->hasPin(),
            'is_owner' => app(TenantContext::class)->requireTenant()->owner_user_id === $user->id,
            'role' => $role ? ['id' => $role->id, 'key' => $role->key, 'name' => $role->name] : null,
            'all_branches' => $user->relationLoaded('roles') && $user->can('branches.access_all'),
            'branches' => $this->whenLoaded('branches', fn () => $user->branches
                ->sortBy('name')
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])
                ->values()),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
