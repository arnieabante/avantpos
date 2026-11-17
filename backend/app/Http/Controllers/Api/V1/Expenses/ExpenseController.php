<?php

namespace App\Http\Controllers\Api\V1\Expenses;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Expenses\ExpenseService;
use App\Services\Media\MediaStorage;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses of the branches the user can access. People who only record
 * expenses see their own; viewers and approvers see all.
 */
class ExpenseController extends Controller
{
    private const RELATIONS = ['category', 'branch', 'paymentMethod', 'submitter', 'approver', 'attachments'];

    public function __construct(private readonly ExpenseService $expenses) {}

    public function categories(Request $request): JsonResponse
    {
        $this->expenses->ensureCategories();

        return ApiResponse::data(ExpenseCategory::query()->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active])->all());
    }

    /**
     * What the expense form needs: active categories, payment methods (not
     * "charge to account") and the user's branches.
     */
    public function options(Request $request): JsonResponse
    {
        $this->expenses->ensureCategories();
        $user = $this->user($request);

        return ApiResponse::data([
            'categories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'payment_methods' => PaymentMethod::query()->where('is_active', true)->where('type', '!=', 'credit')->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->all(),
            'branches' => Branch::query()->whereIn('id', $this->branchIds($user))->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'can_approve' => $user->can('expenses.approve'),
        ]);
    }

    public function saveCategory(Request $request, ?ExpenseCategory $category = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('expense_categories', 'name')->where('tenant_id', $category?->tenant_id ?? $this->user($request)->tenant_id)->ignore($category?->id)],
            'is_active' => ['boolean'],
        ]);
        $category ??= new ExpenseCategory;
        $category->fill($data + ['is_active' => $data['is_active'] ?? true])->save();

        return ApiResponse::data(['id' => $category->id, 'name' => $category->name, 'is_active' => $category->is_active], $category->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'branch_id' => ['nullable', 'integer'],
            'expense_category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $this->user($request);
        $term = $request->string('search')->trim()->toString();

        $page = Expense::query()->with(self::RELATIONS)
            ->whereIn('branch_id', $this->branchIds($user))
            ->when(! $user->canAny(['expenses.view', 'expenses.approve']), fn ($q) => $q->where('submitted_by', $user->getKey()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->input('to')))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('vendor', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->latest('expense_date')->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::data($page->getCollection()->map(fn ($e) => $this->present($e, $user))->all(), 200, ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);

        return ApiResponse::data($this->present($expense->load(self::RELATIONS), $this->user($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request) + $request->validate([
            'submit' => ['boolean'],
            'paid_from_drawer' => ['boolean'],
        ]);
        $expense = $this->expenses->create($data, $this->user($request));

        return ApiResponse::data($this->present($expense->load(self::RELATIONS), $this->user($request)), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);
        $expense = $this->expenses->update($expense, $this->validated($request), $this->user($request));

        return ApiResponse::data($this->present($expense->load(self::RELATIONS), $this->user($request)));
    }

    public function submit(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);
        if ($expense->submitted_by !== $this->user($request)->getKey()) {
            throw new DomainException('Only the person who recorded it can submit this expense.', 'FORBIDDEN', 403);
        }
        $paid = $request->validate(['paid_from_drawer' => ['boolean']])['paid_from_drawer'] ?? false;

        return ApiResponse::data($this->present($this->expenses->submit($expense, $this->user($request), $paid)->load(self::RELATIONS), $this->user($request)));
    }

    public function approve(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);

        return ApiResponse::data($this->present($this->expenses->approve($expense, $this->user($request))->load(self::RELATIONS), $this->user($request)));
    }

    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return ApiResponse::data($this->present($this->expenses->reject($expense, $reason, $this->user($request))->load(self::RELATIONS), $this->user($request)));
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);
        $this->expenses->delete($expense->load('attachments'), $this->user($request));

        return ApiResponse::message('Draft deleted.');
    }

    public function attach(Request $request, Expense $expense): JsonResponse
    {
        $this->assertVisible($request, $expense);
        $file = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']])['file'];
        $this->expenses->attach($expense, $file, $this->user($request));

        return ApiResponse::data($this->present($expense->load(self::RELATIONS), $this->user($request)), 201);
    }

    public function detach(Request $request, Expense $expense, ExpenseAttachment $attachment): JsonResponse
    {
        $this->assertVisible($request, $expense);
        abort_unless($attachment->expense_id === $expense->id, 404);
        $this->expenses->detach($expense, $attachment, $this->user($request));

        return ApiResponse::data($this->present($expense->load(self::RELATIONS), $this->user($request)));
    }

    public function download(Request $request, Expense $expense, ExpenseAttachment $attachment, MediaStorage $media): StreamedResponse
    {
        $this->assertVisible($request, $expense);
        abort_unless($attachment->expense_id === $expense->id, 404);

        return $media->documents()->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $user = $this->user($request);

        return $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($this->branchIds($user))],
            'expense_category_id' => ['required', 'integer', TenantExists::on('expense_categories')],
            'payment_method_id' => ['nullable', 'integer', TenantExists::on('payment_methods')],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDay()->toDateString()],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['branch_id.in' => 'Choose one of your branches.']);
    }

    /**
     * @return list<int>
     */
    private function branchIds(User $user): array
    {
        return $user->canAccessAllBranches()
            ? Branch::query()->pluck('id')->all()
            : $user->branches()->pluck('branches.id')->all();
    }

    private function assertVisible(Request $request, Expense $expense): void
    {
        $user = $this->user($request);
        $visible = in_array($expense->branch_id, $this->branchIds($user), true)
            && ($user->canAny(['expenses.view', 'expenses.approve']) || $expense->submitted_by === $user->getKey());
        if (! $visible) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Expense $e, User $user): array
    {
        $own = $e->submitted_by === $user->getKey();

        return [
            'id' => $e->id,
            'number' => $e->number,
            'status' => $e->status,
            'expense_date' => $e->expense_date?->toDateString(),
            'amount' => $e->amount,
            'vendor' => $e->vendor,
            'reference_no' => $e->reference_no,
            'description' => $e->description,
            'category' => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
            'branch' => $e->branch ? ['id' => $e->branch->id, 'name' => $e->branch->name] : null,
            'payment_method' => $e->paymentMethod ? ['id' => $e->paymentMethod->id, 'name' => $e->paymentMethod->name] : null,
            'paid_from_drawer' => $e->cash_register_session_id !== null,
            'submitted_by' => $e->submitter ? ['id' => $e->submitter->id, 'name' => $e->submitter->name] : null,
            'approved_by' => $e->approver ? ['id' => $e->approver->id, 'name' => $e->approver->name] : null,
            'approved_at' => $e->approved_at?->toIso8601String(),
            'rejection_reason' => $e->rejection_reason,
            'attachments' => $e->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size])->all(),
            'created_at' => $e->created_at?->toIso8601String(),
            'can' => [
                'edit' => $e->isEditable() && ($own || $user->can('expenses.approve')),
                'submit' => $e->status === 'draft' && $own,
                'decide' => $e->status === 'submitted' && $user->can('expenses.approve'),
                'delete' => $e->status === 'draft' && $own,
                'attach' => $own || $user->can('expenses.approve'),
            ],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
