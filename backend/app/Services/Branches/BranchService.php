<?php

namespace App\Services\Branches;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $this->plan->assertCanAdd('branches');

            $branch = Branch::query()->create($data + ['is_main' => false, 'is_active' => true]);
            $this->audit->log('branch.created', $branch, new: $branch->only(['code', 'name']));

            return $branch;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        $branch->fill($data)->save();
        $this->audit->logChanges('branch.updated', $branch);

        return $branch;
    }

    public function deactivate(Branch $branch): Branch
    {
        if ($branch->is_main) {
            throw new DomainException('The main branch cannot be deactivated. Make another branch the main branch first.', 'BRANCH_IS_MAIN', 422);
        }

        if ($this->hasOpenRegisterSession($branch)) {
            throw new DomainException('This branch has an open cash register session. Close it before deactivating the branch.', 'BRANCH_HAS_OPEN_SESSION', 422);
        }

        return $this->setActive($branch, false);
    }

    public function activate(Branch $branch): Branch
    {
        return $this->setActive($branch, true);
    }

    public function makeMain(Branch $branch): Branch
    {
        if (! $branch->is_active) {
            throw new DomainException('Activate the branch before making it the main branch.', 'BRANCH_INACTIVE', 422);
        }

        if ($branch->is_main) {
            return $branch;
        }

        return DB::transaction(function () use ($branch) {
            $previous = Branch::query()->where('is_main', true)->lockForUpdate()->first();
            Branch::query()->where('is_main', true)->update(['is_main' => false]);
            $branch->forceFill(['is_main' => true])->save();

            $this->audit->log('branch.made_main', $branch, old: ['main_branch_id' => $previous?->id], new: ['main_branch_id' => $branch->id]);

            return $branch;
        });
    }

    private function setActive(Branch $branch, bool $active): Branch
    {
        if ($branch->is_active === $active) {
            return $branch;
        }

        $branch->forceFill(['is_active' => $active])->save();
        $this->audit->log($active ? 'branch.activated' : 'branch.deactivated', $branch);

        return $branch;
    }

    private function hasOpenRegisterSession(Branch $branch): bool
    {
        return DB::table('cash_register_sessions')
            ->where('tenant_id', $this->tenant->requireId())
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->exists();
    }
}
