<?php

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\BranchContext;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Supervisor PIN override at the POS.
 *
 * A cashier lacking a permission (e.g. sales.void) asks a supervisor to
 * enter their PIN. On success a short-lived, single-use override token is
 * issued; the protected action consumes it and records BOTH users in the
 * audit trail.
 */
class SupervisorOverrideService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branch,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{token: string, expires_at: CarbonInterface, supervisor: User}
     */
    public function grant(User $requester, int $supervisorId, string $pin, string $permission): array
    {
        $lockKey = $this->lockKey($supervisorId);
        $maxAttempts = (int) config('avantpos.app.override.max_attempts', 5);

        if ((int) Cache::get($lockKey, 0) >= $maxAttempts) {
            throw new DomainException('Too many wrong PIN attempts. Try again in a few minutes.', 'PIN_LOCKED', 429);
        }

        $supervisor = User::query()->find($supervisorId);

        $valid = $supervisor
            && $supervisor->isActive()
            && $supervisor->checkPin($pin)
            && $supervisor->hasPermissionTo($permission)
            && ($this->branch->id() === null || $supervisor->canAccessBranch($this->branch->id()));

        if (! $valid) {
            Cache::add($lockKey, 0, (int) config('avantpos.app.override.lockout_seconds', 300));
            Cache::increment($lockKey);

            $this->audit->log('auth.override_failed', $supervisor, meta: [
                'permission' => $permission,
                'requested_by' => $requester->getKey(),
            ]);

            throw new DomainException('Supervisor PIN is invalid or not authorized for this action.', 'OVERRIDE_DENIED', 403);
        }

        Cache::forget($lockKey);

        $token = Str::random(48);
        $ttl = (int) config('avantpos.app.override.token_ttl_seconds', 120);
        $expiresAt = now()->addSeconds($ttl);

        Cache::put($this->tokenKey($token), [
            'supervisor_id' => $supervisor->getKey(),
            'requested_by' => $requester->getKey(),
            'permission' => $permission,
        ], $ttl);

        $this->audit->log('auth.override_granted', $supervisor, meta: [
            'permission' => $permission,
            'requested_by' => $requester->getKey(),
        ], authorizedBy: $supervisor);

        return ['token' => $token, 'expires_at' => $expiresAt, 'supervisor' => $supervisor];
    }

    /**
     * Consume an override token for a permission. Single use.
     *
     * @return User The supervisor who authorized the action.
     */
    public function consume(User $requester, string $token, string $permission): User
    {
        $payload = Cache::pull($this->tokenKey($token));

        if (! is_array($payload)
            || $payload['permission'] !== $permission
            || $payload['requested_by'] !== $requester->getKey()) {
            throw new DomainException('Supervisor approval is missing or expired.', 'OVERRIDE_REQUIRED', 403);
        }

        return User::query()->findOrFail($payload['supervisor_id']);
    }

    private function tokenKey(string $token): string
    {
        return 'override:'.$this->tenant->requireId().':'.hash('sha256', $token);
    }

    private function lockKey(int $supervisorId): string
    {
        return 'override-attempts:'.$this->tenant->requireId().':'.$supervisorId;
    }
}
