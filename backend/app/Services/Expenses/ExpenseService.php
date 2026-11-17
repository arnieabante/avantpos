<?php

namespace App\Services\Expenses;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Cash\CashSessionService;
use App\Services\Media\MediaStorage;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Expenses with an approval workflow:
 *
 *   draft → submitted → approved | rejected
 *
 * Someone who may approve expenses has their own expenses approved on
 * submission. Only approved expenses count in reports. An expense paid
 * out of a cash drawer is recorded as a payout of the recorder's open shift
 * when it is submitted (the cash has left), so the drawer count stays right.
 */
class ExpenseService
{
    public const MAX_ATTACHMENTS = 5;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly CashSessionService $cash,
        private readonly MediaStorage $media,
        private readonly AuditLogger $audit,
    ) {}

    /** Default categories for a business that has none yet. */
    public function ensureCategories(): void
    {
        if (ExpenseCategory::query()->exists()) {
            return;
        }
        foreach (ExpenseCategory::DEFAULTS as $name) {
            ExpenseCategory::query()->create(['name' => $name, 'is_active' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function create(array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $expense = new Expense;
            $expense->forceFill($this->fields($data) + [
                'number' => $this->numbers->next(DocumentType::Expense),
                'status' => 'draft',
                'submitted_by' => $actor->getKey(),
            ])->save();
            $this->audit->log('expense.created', $expense, new: ['number' => $expense->number, 'amount' => (string) $expense->amount]);

            return ($data['submit'] ?? true) ? $this->submit($expense, $actor, (bool) ($data['paid_from_drawer'] ?? false)) : $expense;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, User $actor): Expense
    {
        $this->assertOwnEditable($expense, $actor);
        if ($expense->cash_register_session_id && Money::of($data['amount'])->compareTo($expense->amount) !== 0) {
            throw ValidationException::withMessages(['amount' => 'This expense was paid out of a cash drawer; its amount cannot change.']);
        }
        $expense->forceFill($this->fields($data))->save();
        $this->audit->log('expense.updated', $expense);

        return $expense;
    }

    public function submit(Expense $expense, User $actor, bool $paidFromDrawer = false): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $paidFromDrawer) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());
            if ($expense->status !== 'draft') {
                throw new DomainException('Only drafts can be submitted.', 'EXPENSE_NOT_DRAFT', 409);
            }

            if ($paidFromDrawer) {
                $session = CashRegisterSession::query()->where('user_id', $actor->getKey())->where('status', 'open')->first();
                if (! $session || $session->branch_id !== $expense->branch_id) {
                    throw ValidationException::withMessages(['paid_from_drawer' => 'Start a shift in this branch to pay from the cash drawer.']);
                }
                $this->cash->recordMovement($session, 'payout', (string) $expense->amount, Str::limit("Expense {$expense->number}".($expense->vendor ? " – {$expense->vendor}" : ''), 250), (string) Str::uuid(), $actor);
                $expense->cash_register_session_id = $session->getKey();
            }

            $auto = $actor->can('expenses.approve');
            $expense->forceFill([
                'status' => $auto ? 'approved' : 'submitted',
                'approved_by' => $auto ? $actor->getKey() : null,
                'approved_at' => $auto ? now() : null,
            ])->save();
            $this->audit->log($auto ? 'expense.approved' : 'expense.submitted', $expense, new: ['status' => $expense->status]);

            return $expense;
        });
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        return $this->decide($expense, $actor, 'approved', null);
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        return $this->decide($expense, $actor, 'rejected', $reason);
    }

    private function decide(Expense $expense, User $actor, string $status, ?string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $status, $reason) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());
            if ($expense->status !== 'submitted') {
                throw new DomainException('Only submitted expenses can be approved or rejected.', 'EXPENSE_NOT_SUBMITTED', 409);
            }
            $expense->forceFill([
                'status' => $status,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ])->save();
            $this->audit->log("expense.{$status}", $expense, ['status' => 'submitted'], ['status' => $status], array_filter(['reason' => $reason]));

            return $expense;
        });
    }

    /** Drafts can be deleted by whoever recorded them; anything submitted stays on record. */
    public function delete(Expense $expense, User $actor): void
    {
        if ($expense->status !== 'draft' || $expense->submitted_by !== $actor->getKey()) {
            throw new DomainException('Only your own drafts can be deleted.', 'EXPENSE_NOT_DELETABLE', 409);
        }
        DB::transaction(function () use ($expense) {
            foreach ($expense->attachments as $a) {
                $this->media->documents()->delete($a->path);
            }
            $expense->attachments()->delete();
            $expense->delete();
        });
        $this->audit->log('expense.deleted', $expense, old: ['number' => $expense->number]);
    }

    public function attach(Expense $expense, UploadedFile $file, User $actor): ExpenseAttachment
    {
        if ($expense->attachments()->count() >= self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['file' => 'Up to '.self::MAX_ATTACHMENTS.' files per expense.']);
        }
        $row = new ExpenseAttachment;
        $row->forceFill([
            'expense_id' => $expense->getKey(),
            'path' => $this->media->storeDocument($file, 'expenses'),
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
        ])->save();
        $this->audit->log('expense.attachment_added', $expense, meta: ['file' => $row->original_name]);

        return $row;
    }

    public function detach(Expense $expense, ExpenseAttachment $attachment, User $actor): void
    {
        if (! $expense->isEditable()) {
            throw new DomainException('Attachments of a decided expense are kept.', 'EXPENSE_LOCKED', 409);
        }
        $this->media->documents()->delete($attachment->path);
        $attachment->delete();
    }

    private function assertOwnEditable(Expense $expense, User $actor): void
    {
        if (! $expense->isEditable()) {
            throw new DomainException('Approved or rejected expenses cannot be changed.', 'EXPENSE_LOCKED', 409);
        }
        if ($expense->submitted_by !== $actor->getKey() && ! $actor->can('expenses.approve')) {
            throw new DomainException('Only the person who recorded it (or an approver) can change this expense.', 'FORBIDDEN', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return [
            'branch_id' => $data['branch_id'],
            'expense_category_id' => $data['expense_category_id'],
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'expense_date' => $data['expense_date'],
            'amount' => (string) Money::of($data['amount']),
            'vendor' => $data['vendor'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }
}
