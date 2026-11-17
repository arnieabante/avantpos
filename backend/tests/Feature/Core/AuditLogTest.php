<?php

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;

it('records actor, tenant, IP and redacts secrets', function () {
    ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

    $log = $this->asTenant($tenant, fn () => app(AuditLogger::class)->log(
        'user.updated', $owner,
        old: ['password' => 'old-hash', 'name' => 'A'],
        new: ['password' => 'new-hash', 'name' => 'B'],
        actor: $owner,
    ));

    expect($log->tenant_id)->toBe($tenant->id)
        ->and($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe($owner->id)
        ->and($log->auditable_type)->toBe('user')
        ->and($log->old_values['password'])->toBe('[redacted]')
        ->and($log->new_values['name'])->toBe('B');
});

it('is append-only', function () {
    ['tenant' => $tenant] = $this->createTenant();
    $log = AuditLog::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect(fn () => $log->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

it('scopes tenant-facing audit queries', function () {
    ['tenant' => $a] = $this->createTenant();
    ['tenant' => $b] = $this->createTenant();

    $tenantIds = $this->asTenant($a, fn () => AuditLog::forCurrentTenant()->pluck('tenant_id')->unique()->all());

    expect($tenantIds)->toBe([$a->id]);
});

it('logs the real before and after values of a saved model', function () {
    ['tenant' => $tenant, 'branch' => $branch] = $this->createTenant();

    $log = $this->asTenant($tenant, function () use ($branch) {
        $branch->update(['name' => 'Quiapo Branch']);

        return app(AuditLogger::class)->logChanges('branch.updated', $branch);
    });

    expect($log->old_values)->toBe(['name' => 'Main Branch'])
        ->and($log->new_values)->toBe(['name' => 'Quiapo Branch']);
});

it('logs nothing when a model did not change', function () {
    ['tenant' => $tenant, 'branch' => $branch] = $this->createTenant();

    expect($this->asTenant($tenant, fn () => app(AuditLogger::class)->logChanges('branch.updated', $branch)))->toBeNull();
});
