<?php

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\Access\RoleAssignmentGuard;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Support\FrontendUrl;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inviting users into a tenant and accepting invitations.
 */
class InvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleAssignmentGuard $guard,
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
        private readonly FrontendUrl $frontend,
    ) {}

    /**
     * @param  array{email: string, name?: ?string, role_id: int, branch_ids?: list<int>}  $data
     */
    public function invite(User $inviter, array $data): UserInvitation
    {
        $email = Str::lower($data['email']);
        $role = Role::forCurrentTenant()->with('permissions')->findOrFail($data['role_id']);
        $branchIds = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));

        $this->guard->assertCanAssignRole($inviter, $role);
        $this->assertCanAssignBranches($inviter, $branchIds);
        $this->assertEmailAvailable($email);

        [$invitation, $plainToken] = DB::transaction(function () use ($inviter, $email, $data, $role, $branchIds) {
            // Re-inviting the same address replaces the previous pending invitation.
            UserInvitation::query()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            $this->plan->assertCanAdd('users');

            $plainToken = Str::random(64);
            $invitation = UserInvitation::query()->create([
                'email' => $email,
                'name' => $data['name'] ?? null,
                'role_id' => $role->getKey(),
                'branch_ids' => $branchIds,
                'token_hash' => UserInvitation::hashToken($plainToken),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addHours((int) config('avantpos.app.invitation_ttl_hours', 72)),
            ]);

            $this->audit->log('user.invited', $invitation, new: [
                'email' => $email,
                'role' => $role->name,
                'branch_ids' => $branchIds,
            ]);

            return [$invitation, $plainToken];
        });

        $this->send($invitation, $plainToken);

        return $invitation;
    }

    /**
     * Invitations the actor may see: all of them with branches.access_all,
     * otherwise the ones they sent or that target one of their branches.
     *
     * @return IlluminateDatabaseEloquentBuilder<UserInvitation>
     */
    public function visibleTo(User $actor)
    {
        $query = UserInvitation::query();

        if (! $actor->canAccessAllBranches()) {
            $branchIds = $actor->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
            $query->where(fn ($q) => $q
                ->where('invited_by', $actor->getKey())
                ->orWhereJsonOverlaps('branch_ids', $branchIds));
        }

        return $query;
    }

    public function assertVisible(User $actor, UserInvitation $invitation): void
    {
        if (! $this->visibleTo($actor)->whereKey($invitation->getKey())->exists()) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }

    public function resend(UserInvitation $invitation): UserInvitation
    {
        if ($invitation->accepted_at || $invitation->revoked_at) {
            throw new DomainException('This invitation is no longer active.', 'INVITATION_INACTIVE', 422);
        }

        $plainToken = Str::random(64);
        $invitation->forceFill([
            'token_hash' => UserInvitation::hashToken($plainToken),
            'expires_at' => now()->addHours((int) config('avantpos.app.invitation_ttl_hours', 72)),
        ])->save();

        $this->audit->log('user.invitation_resent', $invitation, new: ['email' => $invitation->email]);
        $this->send($invitation, $plainToken);

        return $invitation;
    }

    public function revoke(UserInvitation $invitation): void
    {
        if ($invitation->accepted_at) {
            throw new DomainException('This invitation was already accepted.', 'INVITATION_ACCEPTED', 422);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit->log('user.invitation_revoked', $invitation, old: ['email' => $invitation->email]);
    }

    /**
     * Look up a pending invitation by its plain token (public endpoint, no tenant context).
     */
    public function findPending(string $plainToken): UserInvitation
    {
        $invitation = UserInvitation::withoutTenantScope()
            ->where('token_hash', UserInvitation::hashToken($plainToken))
            ->first();

        if (! $invitation || ! $invitation->isPending()) {
            throw new DomainException('This invitation link is invalid or has expired.', 'INVITATION_INVALID', 404);
        }

        return $invitation;
    }

    /**
     * @param  array{name: string, password: string, phone?: ?string}  $data
     */
    public function accept(string $plainToken, array $data): User
    {
        $invitation = $this->findPending($plainToken);
        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        if (! $tenant->allowsAccess()) {
            throw new DomainException('This business account is not active.', 'TENANT_INACTIVE', 403);
        }

        return $this->context->runAs($tenant, function () use ($invitation, $data) {
            return DB::transaction(function () use ($invitation, $data) {
                // Lock the invitation so a double submit cannot create two users.
                $invitation = UserInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
                if (! $invitation->isPending()) {
                    throw new DomainException('This invitation link is invalid or has expired.', 'INVITATION_INVALID', 404);
                }

                $this->assertEmailAvailable($invitation->email);

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'phone' => $data['phone'] ?? null,
                    'status' => UserStatus::Active,
                ]);
                // The e-mailed link proves ownership of the address.
                $user->forceFill(['email_verified_at' => now()])->save();

                $role = Role::forCurrentTenant()->findOrFail($invitation->role_id);
                $user->assignRole($role);

                $branchIds = Branch::query()->whereKey($invitation->branch_ids ?? [])->pluck('id')->all();
                $user->syncBranches($branchIds);

                $invitation->forceFill(['accepted_at' => now()])->save();

                $this->audit->log('user.invitation_accepted', $user, new: [
                    'email' => $user->email,
                    'role' => $role->name,
                    'branch_ids' => $branchIds,
                ], actor: $user);

                return $user->refresh();
            });
        });
    }

    private function send(UserInvitation $invitation, string $plainToken): void
    {
        $tenant = $this->context->requireTenant();
        $url = $this->frontend->forTenant($tenant).'/accept-invitation?'.http_build_query(['token' => $plainToken]);

        Notification::route('mail', $invitation->email)
            ->notify(new UserInvitationNotification($tenant->name, $invitation->role?->name ?? 'Team member', $url, $invitation->expires_at));
    }

    private function assertEmailAvailable(string $email): void
    {
        // One e-mail = one account across the whole platform.
        if (User::withoutTenantScope()->withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This e-mail address is already registered with AvantPOS.',
            ]);
        }
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function assertCanAssignBranches(User $inviter, array $branchIds): void
    {
        foreach ($branchIds as $branchId) {
            if (! $inviter->canAccessBranch($branchId)) {
                throw new DomainException('You can only assign branches you have access to.', 'BRANCH_FORBIDDEN', 403);
            }
        }
    }
}
