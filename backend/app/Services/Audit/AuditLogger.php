<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Terminal;
use App\Models\User;
use App\Tenancy\BranchContext;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Writes the append-only audit trail.
 *
 * Use for every security- or money-relevant event: logins, permission
 * changes, voids, refunds, stock adjustments, price changes, overrides...
 */
class AuditLogger
{
    /** Attributes never written to the audit log. */
    private const REDACTED = ['password', 'pin_hash', 'remember_token', 'two_factor_secret', 'token', 'token_hash'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branch,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        array $meta = [],
        ?Authenticatable $actor = null,
        ?User $authorizedBy = null,
        ?int $tenantId = null,
    ): AuditLog {
        $actor ??= $this->request->user() ?? auth('platform')->user();

        $log = new AuditLog;
        $log->forceFill([
            'tenant_id' => $tenantId ?? $this->tenant->id() ?? ($auditable?->getAttributes()['tenant_id'] ?? null),
            'branch_id' => $this->branch->id(),
            'actor_type' => $this->actorType($actor),
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_name' => $actor?->name ?? null,
            'authorized_by_id' => $authorizedBy?->getKey(),
            'event' => $event,
            'auditable_type' => $auditable ? Str::snake(class_basename($auditable)) : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->clean($old) ?: null,
            'new_values' => $this->clean($new) ?: null,
            'meta' => $meta ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
        ]);
        $log->save();

        return $log;
    }

    /**
     * Log the dirty attributes of a model that is about to be / was just saved.
     */
    public function logChanges(string $event, Model $model, array $meta = []): ?AuditLog
    {
        // After save() the original attributes are already synced to the new values;
        // the previous values are kept in getPrevious().
        $saved = $model->getChanges() !== [];
        $changes = $saved ? $model->getChanges() : $model->getDirty();
        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        $old = Arr::only($saved ? $model->getPrevious() : $model->getRawOriginal(), array_keys($changes));

        return $this->log($event, $model, $old, $changes, $meta);
    }

    private function actorType(?Authenticatable $actor): ?string
    {
        return match (true) {
            $actor instanceof User => 'user',
            $actor instanceof PlatformAdmin => 'platform_admin',
            $actor instanceof Terminal => 'terminal',
            $actor === null => 'system',
            default => Str::snake(class_basename($actor)),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $values);
    }
}
