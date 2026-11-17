<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Resources\Cash\CashSessionResource;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Terminal;
use App\Services\Cash\CashSessionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Register shifts in the branch selected in the app.
 */
class CashSessionController extends InventoryController
{
    private const RELATIONS = ['branch', 'terminal', 'cashRegister', 'user', 'closer'];

    public function __construct(private readonly CashSessionService $sessions) {}

    /**
     * Terminals of the branch with their drawers and who is using them,
     * plus the current user's open shift (anywhere).
     */
    public function terminals(Request $request): JsonResponse
    {
        $branch = $this->branch();
        $open = CashRegisterSession::query()->with('user')->where('branch_id', $branch->getKey())->where('status', 'open')->get();
        $drawers = CashRegister::query()->where('branch_id', $branch->getKey())->where('is_active', true)->orderBy('name')->get();
        $mine = CashRegisterSession::query()->with(self::RELATIONS)->where('user_id', $this->actor($request)->getKey())->where('status', 'open')->first();

        $terminals = Terminal::query()->where('branch_id', $branch->getKey())->where('status', 'active')->orderBy('code')->get()
            ->map(function (Terminal $t) use ($open, $drawers) {
                $session = $open->firstWhere('terminal_id', $t->id);

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'in_use_by' => $session?->user ? ['id' => $session->user->id, 'name' => $session->user->name] : null,
                    'drawers' => $drawers->filter(fn ($d) => $d->terminal_id === $t->id || $d->terminal_id === null)
                        ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'shared' => $d->terminal_id === null,
                            'in_use' => $open->contains('cash_register_id', $d->id)])
                        ->sortBy('shared')->values()->all(),
                ];
            });

        return ApiResponse::data([
            'terminals' => $terminals->all(),
            'my_session' => $mine ? (new CashSessionResource($mine))->toArray($request) : null,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $actor = $this->actor($request);

        $sessions = CashRegisterSession::query()
            ->with(self::RELATIONS)
            ->where('branch_id', $this->branch()->getKey())
            // Cashiers see their own shifts only.
            ->when(! $actor->can('cash.view_all_sessions'), fn ($q) => $q->where('user_id', $actor->getKey()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('opened_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('opened_at', '<=', $request->date('to')->endOfDay()))
            ->latest('opened_at')
            ->paginate($request->integer('per_page', 25));

        return CashSessionResource::collection($sessions);
    }

    public function show(Request $request, CashRegisterSession $cashSession): CashSessionResource
    {
        $this->assertVisible($request, $cashSession);

        return (new CashSessionResource($cashSession->load(self::RELATIONS)))->detailed();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'terminal_id' => ['required', 'integer'],
            'cash_register_id' => ['nullable', 'integer'],
            'opening_float' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
        ]);
        $terminal = Terminal::query()->find($data['terminal_id'])
            ?? throw ValidationException::withMessages(['terminal_id' => 'Choose a terminal.']);

        $session = $this->sessions->open($this->branch(), $terminal, $data['cash_register_id'] ?? null,
            (string) $data['opening_float'], $data['client_uuid'], $this->actor($request));

        return (new CashSessionResource($session->load(self::RELATIONS)))->detailed()->response()->setStatusCode(201);
    }

    public function movement(Request $request, CashRegisterSession $cashSession): CashSessionResource
    {
        $this->assertVisible($request, $cashSession);
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', Rule::in(CashMovement::TYPES)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $this->sessions->recordMovement($cashSession, $data['type'], (string) $data['amount'], $data['reason'], $data['client_uuid'], $this->actor($request));

        return (new CashSessionResource($cashSession->refresh()->load(self::RELATIONS)))->detailed();
    }

    public function close(Request $request, CashRegisterSession $cashSession): CashSessionResource
    {
        $this->assertVisible($request, $cashSession);
        $keys = array_map('strval', array_keys(CashRegisterSession::DENOMINATIONS));
        $data = $request->validate([
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'counted_cash' => ['required_without:denominations', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $unknown = array_diff(array_map('strval', array_keys($data['denominations'] ?? [])), $keys);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['denominations' => 'Unknown denomination: '.implode(', ', $unknown).'.']);
        }

        $session = $this->sessions->close($cashSession, $data['denominations'] ?? null,
            isset($data['counted_cash']) ? (string) $data['counted_cash'] : null, $data['notes'] ?? null, $this->actor($request));

        return (new CashSessionResource($session->load(self::RELATIONS)))->detailed();
    }

    private function assertVisible(Request $request, CashRegisterSession $session): void
    {
        $actor = $this->actor($request);
        $this->assertBranchAccess($request, $session->branch_id);
        if ($session->user_id !== $actor->getKey() && ! $actor->can('cash.view_all_sessions')) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }
}
