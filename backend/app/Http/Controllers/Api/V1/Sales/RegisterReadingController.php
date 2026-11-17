<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Models\CashRegisterSession;
use App\Models\RegisterReading;
use App\Models\Terminal;
use App\Rules\TenantExists;
use App\Services\Sales\RegisterReadingService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * X readings (per shift) and Z readings (end of day) of the branch's terminals.
 */
class RegisterReadingController extends InventoryController
{
    public function __construct(private readonly RegisterReadingService $readings) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(['X', 'Z'])],
            'terminal_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $terminals = Terminal::query()->where('branch_id', $this->branch()->getKey())->pluck('id');

        $page = RegisterReading::query()->with(['terminal', 'generator'])
            ->whereIn('terminal_id', $terminals)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('terminal_id'), fn ($q) => $q->where('terminal_id', $request->integer('terminal_id')))
            ->latest('generated_at')->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::data(
            $page->getCollection()->map(fn ($r) => $this->present($r))->all(), 200,
            ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]],
        );
    }

    public function show(RegisterReading $reading): JsonResponse
    {
        $this->assertInBranch($reading->terminal_id);

        return ApiResponse::data($this->present($reading->load(['terminal', 'generator'])));
    }

    /**
     * X reading of a shift: by a supervisor, or the cashier for their own shift.
     */
    public function x(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertInBranch($cashSession->terminal_id);
        if (! $actor->can('cash.z_reading') && $cashSession->user_id !== $actor->getKey()) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }

        return ApiResponse::data($this->present($this->readings->x($cashSession, $actor)->load(['terminal', 'generator'])), 201);
    }

    public function z(Request $request): JsonResponse
    {
        $data = $request->validate(['terminal_id' => ['required', 'integer', TenantExists::on('terminals')]]);
        $terminal = Terminal::query()->findOrFail($data['terminal_id']);
        $this->assertInBranch($terminal->getKey());

        return ApiResponse::data($this->present($this->readings->z($terminal, $this->actor($request))->load(['terminal', 'generator'])), 201);
    }

    private function assertInBranch(int $terminalId): void
    {
        if (Terminal::query()->whereKey($terminalId)->value('branch_id') !== $this->branch()->getKey()) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RegisterReading $r): array
    {
        $t = $r->terminal;

        return [
            'id' => $r->id,
            'type' => $r->type,
            'reading_no' => $r->reading_no,
            'business_date' => $r->business_date?->toDateString(),
            'generated_at' => $r->generated_at?->toIso8601String(),
            'generated_by' => $r->generator ? ['id' => $r->generator->id, 'name' => $r->generator->name] : null,
            'terminal' => $t ? ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'min' => $t->min, 'serial_no' => $t->serial_no, 'ptu_no' => $t->ptu_no] : null,
            'cash_register_session_id' => $r->cash_register_session_id,
            'beginning_invoice_no' => $r->beginning_invoice_no,
            'ending_invoice_no' => $r->ending_invoice_no,
            'transaction_count' => $r->transaction_count,
            'gross_sales' => $r->gross_sales,
            'sc_discount' => $r->sc_discount,
            'pwd_discount' => $r->pwd_discount,
            'other_discount' => $r->other_discount,
            'void_amount' => $r->void_amount,
            'sc_pwd_vat' => $r->payment_breakdown['sc_pwd_vat'] ?? '0.00',
            'refund_amount' => $r->refund_amount,
            'net_sales' => $r->net_sales,
            'vatable_sales' => $r->vatable_sales,
            'vat_amount' => $r->vat_amount,
            'vat_exempt_sales' => $r->vat_exempt_sales,
            'zero_rated_sales' => $r->zero_rated_sales,
            'accumulated_grand_total_before' => $r->accumulated_grand_total_before,
            'accumulated_grand_total_after' => $r->accumulated_grand_total_after,
            'void_count' => $r->payment_breakdown['void_count'] ?? 0,
            'refund_count' => $r->payment_breakdown['refund_count'] ?? 0,
            'payments' => $r->payment_breakdown['methods'] ?? [],
        ];
    }
}
