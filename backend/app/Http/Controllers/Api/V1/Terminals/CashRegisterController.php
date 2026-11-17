<?php

namespace App\Http\Controllers\Api\V1\Terminals;

use App\Enums\TerminalStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Terminals\CashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use App\Models\CashRegister;
use App\Rules\TenantExists;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawers. A drawer normally belongs to a terminal; a drawer without a
 * terminal can be shared by several terminals of the branch (Phase 5).
 */
class CashRegisterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['branch_id' => ['nullable', 'integer', TenantExists::on('branches')]]);

        $registers = CashRegister::query()
            ->with(['branch', 'terminal'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return CashRegisterResource::collection($registers);
    }

    public function store(CashRegisterRequest $request): JsonResponse
    {
        $register = CashRegister::query()->create($request->validated() + ['is_active' => true]);
        $this->audit->log('cash_register.created', $register, new: $register->only(['name', 'branch_id', 'terminal_id']));

        return (new CashRegisterResource($register->load(['branch', 'terminal'])))->response()->setStatusCode(201);
    }

    public function update(CashRegisterRequest $request, CashRegister $cashRegister): CashRegisterResource
    {
        $cashRegister->fill($request->validated())->save();
        $this->audit->logChanges('cash_register.updated', $cashRegister);

        return new CashRegisterResource($cashRegister->load(['branch', 'terminal']));
    }

    public function activate(CashRegister $cashRegister): CashRegisterResource
    {
        return $this->setActive($cashRegister, true);
    }

    public function deactivate(CashRegister $cashRegister): CashRegisterResource
    {
        return $this->setActive($cashRegister, false);
    }

    private function setActive(CashRegister $register, bool $active): CashRegisterResource
    {
        if ($active && $register->terminal?->status === TerminalStatus::Retired) {
            throw new DomainException('This drawer belongs to a retired terminal.', 'TERMINAL_RETIRED', 422);
        }

        if (! $active && DB::table('cash_register_sessions')
            ->where('tenant_id', $register->tenant_id)
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->exists()) {
            throw new DomainException('This drawer has an open cash register session. Close it first.', 'REGISTER_HAS_OPEN_SESSION', 422);
        }

        if ($register->is_active !== $active) {
            $register->forceFill(['is_active' => $active])->save();
            $this->audit->log($active ? 'cash_register.activated' : 'cash_register.deactivated', $register);
        }

        return new CashRegisterResource($register->load(['branch', 'terminal']));
    }
}
