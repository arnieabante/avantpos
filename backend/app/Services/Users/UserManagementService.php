<?php

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Access\RoleAssignmentGuard;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Managing the users of a business.
 *
 * Rules (on top of the users.* permissions checked by the routes):
 *  - Branch-limited users (no branches.access_all) only see and manage users
 *    who share one of their branches.
 *  - Nobody manages a user who has permissions they do not have themselves.
 *  - The owner cannot be changed or deactivated by anyone else.
 *  - Nobody changes their own role or branches, or deactivates themselves.
 *  - Deactivating a user signs them out everywhere.
 */
class UserManagementService
{
    public function __construct(
        private readonly RoleAssignmentGuard $guard,
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Users the actor may see.
     *
     * @return Builder<User>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = User::query();

        if (! $actor->canAccessAllBranches()) {
            $branchIds = $actor->branches()->pluck('branches.id')->all();
            $query->where(fn (Builder $q) => $q
                ->whereKey($actor->getKey())
                ->orWhereHas('branches', fn (Builder $b) => $b->whereIn('branches.id', $branchIds)));
        }

        return $query;
    }

    public function assertVisible(User $actor, User $target): void
    {
        if (! $this->visibleTo($actor)->whereKey($target->getKey())->exists()) {
            // Same answer as a missing record: do not reveal users of other branches.
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }

    /**
     * Adds a user directly with a starting password (the alternative to an invitation).
     * Same rules as inviting: assignable role only, the actor's own active branches,
     * the plan's user limit, one e-mail = one account across AvantPOS.
     * The e-mail counts as verified: the owner/administrator vouches for it.
     *
     * @param  array{name: string, email: string, phone?: ?string, role_id: int, branch_ids: list<int>, password: string}  $data
     */
    public function create(User $actor, array $data): User
    {
        $email = Str::lower($data['email']);
        $role = Role::forCurrentTenant()->with('permissions')->findOrFail($data['role_id']);
        $branchIds = array_values(array_unique(array_map('intval', $data['branch_ids'])));

        if ($role->key === 'owner') {
            throw new DomainException('A business has only one owner.', 'OWNER_LOCKED', 403);
        }
        $this->guard->assertCanAssignRole($actor, $role);
        $this->assertCanAssignBranches($actor, $branchIds);

        if (User::withoutTenantScope()->withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This e-mail address is already registered with AvantPOS.']);
        }

        return DB::transaction(function () use ($actor, $data, $email, $role, $branchIds) {
            $this->plan->assertCanAdd('users');

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'status' => UserStatus::Active,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => true])->save();
            $user->assignRole($role);
            $user->syncBranches($branchIds);

            // Pending invitations for the same address are no longer needed.
            UserInvitation::query()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            $this->audit->log('user.created', $user, new: [
                'name' => $user->name,
                'email' => $email,
                'role' => $role->name,
                'branch_ids' => $branchIds,
            ], actor: $actor);

            return $user->refresh();
        });
    }

    /**
     * @param  array{name?: string, phone?: ?string, role_id?: int, branch_ids?: list<int>}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->assertCanManage($actor, $target);

        $isSelf = $actor->is($target);
        $role = isset($data['role_id']) ? Role::forCurrentTenant()->with('permissions')->findOrFail($data['role_id']) : null;
        $currentRole = $this->roleOf($target);
        $roleChanges = $role && $role->id !== $currentRole?->id;

        $branchIds = isset($data['branch_ids']) ? array_values(array_unique(array_map('intval', $data['branch_ids']))) : null;
        $currentBranchIds = $target->branches()->pluck('branches.id')->sort()->values()->all();
        $branchesChange = $branchIds !== null && collect($branchIds)->sort()->values()->all() !== $currentBranchIds;

        if (($roleChanges || $branchesChange) && $isSelf) {
            throw new DomainException('You cannot change your own role or branches. Ask another administrator.', 'CANNOT_CHANGE_SELF', 403);
        }

        if (($roleChanges || $branchesChange) && $this->isOwner($target)) {
            throw new DomainException('The owner always has full access; their role and branches cannot be changed.', 'OWNER_LOCKED', 403);
        }

        if ($roleChanges) {
            $this->guard->assertCanAssignRole($actor, $role);
        }

        if ($branchesChange) {
            $this->assertCanAssignBranches($actor, $branchIds);
        }

        return DB::transaction(function () use ($target, $data, $role, $roleChanges, $currentRole, $branchIds, $branchesChange, $currentBranchIds) {
            $target->fill(Arr::only($data, ['name', 'phone']))->save();
            $this->audit->logChanges('user.updated', $target);

            if ($roleChanges) {
                $target->syncRoles([$role]);
                $this->audit->log('user.role_changed', $target,
                    old: ['role' => $currentRole?->name],
                    new: ['role' => $role->name],
                );
            }

            if ($branchesChange) {
                $target->syncBranches($branchIds);
                $this->audit->log('user.branches_changed', $target, old: ['branch_ids' => $currentBranchIds], new: ['branch_ids' => $branchIds]);
            }

            return $target->refresh();
        });
    }

    public function deactivate(User $actor, User $target): User
    {
        $this->assertCanManage($actor, $target);

        if ($actor->is($target)) {
            throw new DomainException('You cannot deactivate your own account.', 'CANNOT_CHANGE_SELF', 403);
        }

        if ($this->isOwner($target)) {
            throw new DomainException('The owner account cannot be deactivated.', 'OWNER_LOCKED', 403);
        }

        if ($target->status === UserStatus::Inactive) {
            return $target;
        }

        return DB::transaction(function () use ($target) {
            $target->forceFill(['status' => UserStatus::Inactive])->save();

            // Sign out everywhere: API tokens and browser sessions.
            $target->tokens()->delete();
            DB::table('sessions')->where('user_id', $target->getKey())->delete();

            $this->audit->log('user.deactivated', $target);

            return $target;
        });
    }

    public function activate(User $actor, User $target): User
    {
        $this->assertCanManage($actor, $target);

        if ($target->status === UserStatus::Active) {
            return $target;
        }

        return DB::transaction(function () use ($target) {
            $this->plan->assertCanAdd('users');

            $target->forceFill(['status' => UserStatus::Active])->save();
            $this->audit->log('user.activated', $target);

            return $target;
        });
    }

    /**
     * Removes the user's POS PIN so they must set a new one (e.g. forgotten PIN).
     */
    public function clearPin(User $actor, User $target): User
    {
        $this->assertCanManage($actor, $target);

        $target->forceFill(['pin_hash' => null])->save();
        $this->audit->log('user.pin_cleared', $target);

        return $target;
    }

    public function sendPasswordReset(User $actor, User $target): void
    {
        $this->assertCanManage($actor, $target);

        if (! $target->isActive()) {
            throw new DomainException('Activate the user before sending a password reset link.', 'USER_INACTIVE', 422);
        }

        Password::broker()->sendResetLink(['email' => $target->email]);
        $this->audit->log('user.password_reset_sent', $target);
    }

    /**
     * Owner / administrator sets a temporary password. The user must change it
     * at their next sign-in and is signed out everywhere now.
     */
    public function setPassword(User $actor, User $target, string $password): User
    {
        $this->assertCanManage($actor, $target);

        if ($actor->is($target)) {
            throw new DomainException('Change your own password under My account.', 'CANNOT_CHANGE_SELF', 403);
        }

        if (! $target->isActive()) {
            throw new DomainException('Activate the user before setting a new password.', 'USER_INACTIVE', 422);
        }

        return DB::transaction(function () use ($target, $password) {
            $target->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => null])->save();

            $target->tokens()->delete();
            DB::table('sessions')->where('user_id', $target->getKey())->delete();

            $this->audit->log('user.password_set', $target);

            return $target;
        });
    }

    public function roleOf(User $user): ?Role
    {
        /** @var Role|null */
        return $user->roles->first();
    }

    /**
     * Roles the actor may give to others: never Owner, and only roles whose
     * permissions the actor holds.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor)
    {
        $held = $actor->getAllPermissions()->pluck('name')->flip();

        return Role::forCurrentTenant()
            ->with('permissions')
            ->where(fn ($q) => $q->whereNull('key')->orWhere('key', '!=', 'owner'))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role) => $role->permissions->every(fn ($p) => $held->has($p->name)))
            ->values();
    }

    private function assertCanManage(User $actor, User $target): void
    {
        $this->assertVisible($actor, $target);

        if ($actor->is($target)) {
            return;
        }

        if ($this->isOwner($target)) {
            throw new DomainException('Only the owner can change the owner account.', 'OWNER_LOCKED', 403);
        }

        // Never manage someone more powerful than yourself.
        $this->guard->assertCanGrantPermissions($actor, $target->getAllPermissions()->pluck('name'));
    }

    private function isOwner(User $user): bool
    {
        return $user->isOwner();
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function assertCanAssignBranches(User $actor, array $branchIds): void
    {
        $active = Branch::query()->whereKey($branchIds)->where('is_active', true)->pluck('id')->all();

        foreach ($branchIds as $branchId) {
            if (! in_array($branchId, $active, true) || ! $actor->canAccessBranch($branchId)) {
                throw new DomainException('You can only assign active branches you have access to.', 'BRANCH_FORBIDDEN', 403);
            }
        }
    }
}
