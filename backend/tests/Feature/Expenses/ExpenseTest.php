<?php

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->cash = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->where('type', 'cash')->value('id'));
    $this->staff = $this->createUser($this->tenant, 'branch_manager'); // may record, not approve
    ($this->as)($this->staff);
    $this->category = fn (string $name) => collect($this->getJson('/api/v1/expense-categories')->assertOk()->json('data'))->firstWhere('name', $name)['id'];
    $this->body = fn (array $o = []) => $o + [
        'branch_id' => $this->main->id, 'expense_category_id' => ($this->category)('Electricity'), 'payment_method_id' => $this->cash,
        'expense_date' => now('Asia/Manila')->toDateString(), 'amount' => '2450.75', 'vendor' => 'Meralco', 'reference_no' => 'BILL-1105',
    ];
});

it('gives every business default categories', function () {
    $names = collect($this->getJson('/api/v1/expense-categories')->json('data'))->pluck('name');
    expect($names)->toContain('Rent', 'Electricity', 'Salaries and wages', 'Others');
    $this->asTenant($this->tenant, fn () => expect(ExpenseCategory::query()->count())->toBe(count(ExpenseCategory::DEFAULTS)));
});

it('records, submits, approves and rejects expenses; only approved ones count in reports', function () {
    $draft = $this->postJson('/api/v1/expenses', ($this->body)(['submit' => false]))->assertCreated();
    expect($draft->json('data'))->toMatchArray(['status' => 'draft', 'amount' => '2450.75'])
        ->and($draft->json('data.number'))->toStartWith('EXP-')
        ->and($draft->json('data.can'))->toMatchArray(['submit' => true, 'decide' => false]);

    $id = $draft->json('data.id');
    $this->putJson("/api/v1/expenses/{$id}", ($this->body)(['amount' => '2500']))->assertOk()->assertJsonPath('data.amount', '2500.00');
    $this->postJson("/api/v1/expenses/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("/api/v1/expenses/{$id}/approve")->assertForbidden(); // cannot approve

    $other = $this->postJson('/api/v1/expenses', ($this->body)(['amount' => '300', 'vendor' => 'Hardware']))->assertCreated()->json('data.id');

    ($this->as)($this->owner);
    $this->postJson("/api/v1/expenses/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by.id', $this->owner->id);
    $this->postJson("/api/v1/expenses/{$other}/reject", ['reason' => 'Personal purchase'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->putJson("/api/v1/expenses/{$id}", ($this->body)())->assertStatus(409)->assertJsonPath('code', 'EXPENSE_LOCKED');

    // The owner's own expenses are approved right away.
    $this->postJson('/api/v1/expenses', ($this->body)(['amount' => '100']))->assertCreated()->assertJsonPath('data.status', 'approved');

    $report = $this->getJson('/api/v1/reports/expenses?from='.now('Asia/Manila')->toDateString())->assertOk();
    expect($report->json('data.totals.amount'))->toBe('2600.00'); // 2500 + 100, rejected excluded
    expect($this->getJson('/api/v1/reports/expenses?group=category')->json('data.rows.0'))->toMatchArray(['category' => 'Electricity', 'amount' => '2600.00']);

    $this->asTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'expense.rejected')->exists())->toBeTrue());
});

it('pays an expense out of the cash drawer of the open shift', function () {
    $terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->postJson('/api/v1/expenses', ($this->body)(['paid_from_drawer' => true]))->assertUnprocessable()->assertJsonValidationErrors('paid_from_drawer');

    $shift = $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $terminal->id, 'opening_float' => '3000'])->json('data.id');
    $this->postJson('/api/v1/expenses', ($this->body)(['paid_from_drawer' => true]))->assertCreated()->assertJsonPath('data.paid_from_drawer', true);

    ($this->as)($this->owner);
    $this->getJson("/api/v1/cash-sessions/{$shift}")->assertJsonPath('data.summary.payouts', '2450.75')->assertJsonPath('data.summary.expected_cash', '549.25');
});

it('keeps receipts private, and shows people who only record expenses just their own', function () {
    $id = $this->postJson('/api/v1/expenses', ($this->body)(['submit' => false]))->json('data.id');
    $this->postJson("/api/v1/expenses/{$id}/attachments", ['file' => UploadedFile::fake()->create('bill.pdf', 120, 'application/pdf')])
        ->assertCreated()->assertJsonPath('data.attachments.0.name', 'bill.pdf');
    $attachment = $this->getJson("/api/v1/expenses/{$id}")->json('data.attachments.0.id');
    $this->get("/api/v1/expenses/{$id}/attachments/{$attachment}")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->postJson("/api/v1/expenses/{$id}/attachments", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertUnprocessable();

    $cashier = $this->createUser($this->tenant, 'cashier');
    $cashier->givePermissionTo('expenses.create');
    ($this->as)($cashier);
    $this->getJson('/api/v1/expenses')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/expenses/{$id}")->assertNotFound();

    // Drafts can be deleted by whoever recorded them.
    ($this->as)($this->staff);
    $this->deleteJson("/api/v1/expenses/{$id}")->assertOk();
    $this->getJson('/api/v1/expenses')->assertJsonCount(0, 'data');
});

it('needs the expenses plan feature', function () {
    usePlan($this->tenant->id, 'test-limited');
    $this->getJson('/api/v1/expenses')->assertStatus(402);
});
