<?php

namespace App\Http\Controllers\Api\V1\Terminals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Terminals\TerminalRequest;
use App\Http\Resources\TerminalResource;
use App\Models\Terminal;
use App\Rules\TenantExists;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TerminalController extends Controller
{
    public function __construct(private readonly TerminalService $terminals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'branch_id' => ['nullable', 'integer', TenantExists::on('branches')],
            'status' => ['nullable', 'in:active,inactive,retired'],
        ]);

        $terminals = Terminal::query()
            ->with(['branch', 'cashRegisters'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByRaw("FIELD(status, 'active', 'inactive', 'retired')")
            ->orderBy('code')
            ->get();

        return TerminalResource::collection($terminals);
    }

    public function store(TerminalRequest $request): JsonResponse
    {
        $terminal = $this->terminals->create($request->validated());

        return (new TerminalResource($terminal->load(['branch', 'cashRegisters'])))->response()->setStatusCode(201);
    }

    public function show(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($terminal->load(['branch', 'cashRegisters']));
    }

    public function update(TerminalRequest $request, Terminal $terminal): TerminalResource
    {
        return new TerminalResource($this->terminals->update($terminal, $request->validated())->load(['branch', 'cashRegisters']));
    }

    public function activate(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($this->terminals->activate($terminal)->load(['branch', 'cashRegisters']));
    }

    public function deactivate(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($this->terminals->deactivate($terminal)->load(['branch', 'cashRegisters']));
    }

    public function retire(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($this->terminals->retire($terminal)->load(['branch', 'cashRegisters']));
    }
}
