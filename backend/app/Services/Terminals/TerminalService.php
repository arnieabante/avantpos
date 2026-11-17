<?php

namespace App\Services\Terminals;

use App\Enums\TerminalStatus;
use App\Exceptions\DomainException;
use App\Models\CashRegister;
use App\Models\Terminal;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * POS terminals (devices) and their cash drawers.
 *
 * Status: active <-> inactive, and retired (permanent). A terminal's invoice
 * series must never be reused, so terminals are never deleted.
 */
class TerminalService
{
    public function __construct(
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
        private readonly TerminalDeviceService $devices,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Terminal
    {
        return DB::transaction(function () use ($data) {
            $this->plan->assertCanAdd('terminals');

            $terminal = Terminal::query()->create(
                Arr::except($data, ['create_cash_register']) + ['status' => TerminalStatus::Active]
            );
            $this->audit->log('terminal.created', $terminal, new: $terminal->only(['code', 'name', 'branch_id']));

            if ($data['create_cash_register'] ?? true) {
                CashRegister::query()->create([
                    'branch_id' => $terminal->branch_id,
                    'terminal_id' => $terminal->id,
                    'name' => "{$terminal->name} drawer",
                    'is_active' => true,
                ]);
            }

            return $terminal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Terminal $terminal, array $data): Terminal
    {
        $this->assertNotRetired($terminal);

        $movingBranch = isset($data['branch_id']) && (int) $data['branch_id'] !== $terminal->branch_id;

        if ($movingBranch && $this->hasRegisterHistory($terminal)) {
            throw new DomainException(
                'This terminal already has cash register sessions in its branch and cannot be moved. Create a new terminal for the other branch.',
                'TERMINAL_HAS_HISTORY',
                422,
            );
        }

        return DB::transaction(function () use ($terminal, $data, $movingBranch) {
            $terminal->fill($data)->save();
            $this->audit->logChanges('terminal.updated', $terminal);

            if ($movingBranch) {
                // Its drawers move with the terminal.
                CashRegister::query()->where('terminal_id', $terminal->id)->update(['branch_id' => $terminal->branch_id]);
            }

            return $terminal;
        });
    }

    public function activate(Terminal $terminal): Terminal
    {
        $this->assertNotRetired($terminal);

        return $this->setStatus($terminal, TerminalStatus::Active);
    }

    public function deactivate(Terminal $terminal): Terminal
    {
        $this->assertNotRetired($terminal);
        $this->assertNoOpenSession($terminal);

        return $this->setStatus($terminal, TerminalStatus::Inactive);
    }

    /**
     * Permanently takes a terminal out of service: revokes its device and
     * deactivates its drawers. The record stays for audit/BIR history.
     */
    public function retire(Terminal $terminal): Terminal
    {
        $this->assertNotRetired($terminal);
        $this->assertNoOpenSession($terminal);

        return DB::transaction(function () use ($terminal) {
            if ($terminal->device_registered_at) {
                $this->devices->revoke($terminal);
            }

            CashRegister::query()->where('terminal_id', $terminal->id)->update(['is_active' => false]);

            return $this->setStatus($terminal, TerminalStatus::Retired);
        });
    }

    private function setStatus(Terminal $terminal, TerminalStatus $status): Terminal
    {
        if ($terminal->status === $status) {
            return $terminal;
        }

        $old = $terminal->status->value;
        $terminal->forceFill(['status' => $status])->save();
        $this->audit->log('terminal.status_changed', $terminal, old: ['status' => $old], new: ['status' => $status->value]);

        return $terminal;
    }

    private function assertNotRetired(Terminal $terminal): void
    {
        if ($terminal->status === TerminalStatus::Retired) {
            throw new DomainException('This terminal is retired and can no longer be changed.', 'TERMINAL_RETIRED', 422);
        }
    }

    private function assertNoOpenSession(Terminal $terminal): void
    {
        $open = DB::table('cash_register_sessions')
            ->where('tenant_id', $this->tenant->requireId())
            ->where('terminal_id', $terminal->id)
            ->where('status', 'open')
            ->exists();

        if ($open) {
            throw new DomainException('This terminal has an open cash register session. Close it first.', 'TERMINAL_HAS_OPEN_SESSION', 422);
        }
    }

    private function hasRegisterHistory(Terminal $terminal): bool
    {
        return DB::table('cash_register_sessions')
            ->where('tenant_id', $this->tenant->requireId())
            ->where('terminal_id', $terminal->id)
            ->exists();
    }
}
