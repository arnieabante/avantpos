<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Purchasing\PayablesService;
use App\Support\Api\ApiResponse;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Supplier bills, supplier payments and supplier statements (accounts payable).
 */
class PayablesController extends Controller
{
    public function __construct(private readonly PayablesService $payables) {}

    /* ------------------------------------------------------------------ bills */

    public function bills(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['open', 'partially_paid', 'paid', 'void', 'unpaid', 'overdue'])],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $request->input('status');
        $page = SupplierBill::query()->with(['supplier', 'goodsReceipt'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when(in_array($status, ['open', 'partially_paid', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
            ->when($status === 'unpaid', fn ($q) => $q->whereIn('status', ['open', 'partially_paid']))
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<', now('Asia/Manila')->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.addcslashes((string) $request->input('search'), '%_\\').'%')
                ->orWhere('supplier_invoice_no', 'like', '%'.addcslashes((string) $request->input('search'), '%_\\').'%')))
            ->orderByRaw("FIELD(status, 'open', 'partially_paid', 'paid', 'void')")->orderBy('due_date')->orderBy('id')
            ->paginate($request->integer('per_page', 25));

        return $this->page($page, fn (SupplierBill $b) => $this->bill($b));
    }

    public function showBill(SupplierBill $bill): JsonResponse
    {
        $bill->load(['supplier', 'goodsReceipt', 'allocations.payment.paymentMethod']);

        return ApiResponse::data($this->bill($bill) + [
            'payments' => $bill->allocations->map(fn ($a) => [
                'id' => $a->payment->id,
                'number' => $a->payment->number,
                'paid_at' => $a->payment->paid_at?->toDateString(),
                'method' => $a->payment->paymentMethod?->name,
                'amount' => $a->amount,
                'status' => $a->payment->status,
            ])->all(),
        ]);
    }

    public function storeBill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goods_receipt_id' => ['nullable', 'integer', TenantExists::on('goods_receipts')],
            'supplier_id' => ['required_without:goods_receipt_id', 'nullable', 'integer', TenantExists::on('suppliers')],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'total' => ['required_without:goods_receipt_id', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999'],
        ]);

        return ApiResponse::data($this->bill($this->payables->createBill($data, $this->user($request))->load(['supplier', 'goodsReceipt'])), 201);
    }

    public function voidBill(Request $request, SupplierBill $bill): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return ApiResponse::data($this->bill($this->payables->voidBill($bill, $reason, $this->user($request))->load(['supplier', 'goodsReceipt'])));
    }

    /** Posted receipts that have no bill yet (to bill them). */
    public function billableReceipts(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => ['nullable', 'integer']]);
        $receipts = GoodsReceipt::query()->with(['supplier', 'branch'])->where('status', 'posted')
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->whereNotIn('id', SupplierBill::query()->whereNotNull('goods_receipt_id')->where('status', '!=', 'void')->select('goods_receipt_id'))
            ->latest('received_at')->limit(100)->get();

        return ApiResponse::data($receipts->map(fn (GoodsReceipt $r) => [
            'id' => $r->id,
            'number' => $r->number,
            'supplier' => $r->supplier ? ['id' => $r->supplier->id, 'name' => $r->supplier->name] : null,
            'branch' => $r->branch?->name,
            'received_at' => $r->received_at?->toIso8601String(),
            'supplier_invoice_no' => $r->supplier_invoice_no,
            'total' => $r->total,
        ])->all());
    }

    /* ------------------------------------------------------------------ payments */

    public function payments(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = SupplierPayment::query()->with(['supplier', 'paymentMethod', 'creator', 'allocations.bill'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->latest('paid_at')->latest('id')
            ->paginate($request->integer('per_page', 25));

        return $this->page($page, fn (SupplierPayment $p) => $this->payment($p));
    }

    /** Payment methods for paying suppliers (not "charge to account"). */
    public function paymentMethods(): JsonResponse
    {
        return ApiResponse::data(PaymentMethod::query()->where('is_active', true)->where('type', '!=', 'credit')->orderBy('sort_order')->get()
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type])->all());
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', TenantExists::on('suppliers')],
            'payment_method_id' => ['nullable', 'integer', TenantExists::on('payment_methods')],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDay()->toDateString()],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_from_drawer' => ['boolean'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.supplier_bill_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ], ['allocations.required' => 'Choose the bills this payment is for.']);

        return ApiResponse::data($this->payment($this->payables->pay($data, $this->user($request))->load(['supplier', 'paymentMethod', 'creator', 'allocations.bill'])), 201);
    }

    public function voidPayment(Request $request, SupplierPayment $payment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return ApiResponse::data($this->payment($this->payables->voidPayment($payment, $reason, $this->user($request))->load(['supplier', 'paymentMethod', 'creator', 'allocations.bill'])));
    }

    /* ------------------------------------------------------------------ statement */

    /**
     * A supplier's bills and payments in date order with the running balance.
     */
    public function statement(Supplier $supplier): JsonResponse
    {
        $bills = SupplierBill::query()->where('supplier_id', $supplier->getKey())->where('status', '!=', 'void')->get()
            ->map(fn ($b) => ['date' => $b->bill_date->toDateString(), 'type' => 'bill', 'id' => $b->id, 'number' => $b->number,
                'reference' => $b->supplier_invoice_no, 'amount' => (string) $b->total, 'sort' => "{$b->bill_date->toDateString()}-0-{$b->id}"]);
        $payments = SupplierPayment::query()->with('paymentMethod')->where('supplier_id', $supplier->getKey())->where('status', 'posted')->get()
            ->map(fn ($p) => ['date' => $p->paid_at->toDateString(), 'type' => 'payment', 'id' => $p->id, 'number' => $p->number,
                'reference' => trim(($p->paymentMethod?->name ?? '').' '.($p->reference_no ?? '')), 'amount' => (string) Money::of($p->amount)->negated(), 'sort' => "{$p->paid_at->toDateString()}-1-{$p->id}"]);

        $balance = Money::zero();
        $rows = $bills->concat($payments)->sortBy('sort')->values()->map(function ($r) use (&$balance) {
            $balance = $balance->plus($r['amount']);
            unset($r['sort']);

            return $r + ['balance' => (string) $balance];
        })->all();

        return ApiResponse::data([
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'payment_terms_days' => $supplier->payment_terms_days],
            'balance' => (string) $balance,
            'entries' => $rows,
        ]);
    }

    /* ------------------------------------------------------------------ presenters */

    /**
     * @return array<string, mixed>
     */
    private function bill(SupplierBill $b): array
    {
        $balance = Money::of($b->total)->minus($b->amount_paid);
        $overdue = in_array($b->status, ['open', 'partially_paid'], true) && $b->due_date && $b->due_date->toDateString() < now('Asia/Manila')->toDateString();

        return [
            'id' => $b->id,
            'number' => $b->number,
            'status' => $b->status,
            'supplier' => $b->supplier ? ['id' => $b->supplier->id, 'name' => $b->supplier->name] : null,
            'goods_receipt' => $b->goodsReceipt ? ['id' => $b->goodsReceipt->id, 'number' => $b->goodsReceipt->number] : null,
            'supplier_invoice_no' => $b->supplier_invoice_no,
            'bill_date' => $b->bill_date?->toDateString(),
            'due_date' => $b->due_date?->toDateString(),
            'total' => $b->total,
            'amount_paid' => $b->amount_paid,
            'balance' => (string) $balance,
            'overdue' => $overdue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(SupplierPayment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'status' => $p->status,
            'supplier' => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'paid_at' => $p->paid_at?->toDateString(),
            'amount' => $p->amount,
            'method' => $p->paymentMethod?->name,
            'reference_no' => $p->reference_no,
            'notes' => $p->notes,
            'created_by' => $p->creator ? ['id' => $p->creator->id, 'name' => $p->creator->name] : null,
            'allocations' => $p->allocations->map(fn ($a) => ['bill_id' => $a->supplier_bill_id, 'bill_number' => $a->bill?->number, 'amount' => $a->amount])->all(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    private function page(LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return ApiResponse::data($page->getCollection()->map($present)->all(), 200, ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
