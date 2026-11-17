<?php

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->terminal2 = $this->createTerminal($this->tenant, $this->main);
    [$this->drawer, $this->drawer2] = $this->asTenant($this->tenant, fn () => [
        CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer 1', 'is_active' => true]),
        CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal2->id, 'name' => 'Drawer 2', 'is_active' => true]),
    ]);
    $this->cashier = $this->createUser($this->tenant, 'cashier');
    $this->cashier2 = $this->createUser($this->tenant, 'cashier');
    $this->manager = $this->createUser($this->tenant, 'branch_manager');

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->open = fn (array $overrides = []) => $this->postJson('/api/v1/cash-sessions', $overrides + [
        'client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '2000',
    ]);
    $this->move = fn (int $id, string $type, string $amount) => $this->postJson("/api/v1/cash-sessions/{$id}/movements", [
        'client_uuid' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Test movement',
    ]);
});

it('opens one shift per terminal and per cashier, idempotently', function () {
    ($this->as)($this->cashier);
    $uuid = (string) Str::uuid();
    $first = ($this->open)(['client_uuid' => $uuid])->assertCreated();
    expect($first->json('data.drawer.name'))->toBe('Drawer 1')
        ->and($first->json('data.opening_float'))->toBe('2000.00')
        ->and($first->json('data.status'))->toBe('open');

    // A retried request returns the same shift instead of opening another.
    ($this->open)(['client_uuid' => $uuid])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

    // Same cashier, another terminal: refused.
    ($this->open)(['terminal_id' => $this->terminal2->id])->assertStatus(409)->assertJsonPath('code', 'SESSION_ALREADY_OPEN');

    // Another cashier on the same terminal: refused.
    ($this->as)($this->cashier2);
    ($this->open)()->assertStatus(409)->assertJsonPath('code', 'TERMINAL_IN_USE');
    ($this->open)(['terminal_id' => $this->terminal2->id])->assertCreated();

    // Terminals are listed by code (random in tests), so look them up by id.
    $listed = $this->getJson('/api/v1/pos/terminals')->assertOk()
        ->assertJsonPath('data.my_session.terminal.id', $this->terminal2->id);
    expect(collect($listed->json('data.terminals'))->firstWhere('id', $this->terminal->id)['in_use_by']['id'])->toBe($this->cashier->id);
});

it('refuses terminals of other branches and terminals without a drawer', function () {
    ($this->as)($this->cashier);
    $bare = $this->createTerminal($this->tenant, $this->main);
    ($this->open)(['terminal_id' => $bare->id])->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');

    ['tenant' => $other] = $this->createTenant();
    $foreign = $this->createTerminal($other);
    ($this->open)(['terminal_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('terminal_id');
});

it('records cash in, cash out and payouts, never more than is in the drawer', function () {
    ($this->as)($this->cashier);
    $id = ($this->open)()->json('data.id');

    ($this->move)($id, 'cash_in', '500')->assertOk();
    ($this->move)($id, 'payout', '150.75')->assertOk();
    ($this->move)($id, 'cash_out', '2349.26')->assertUnprocessable()->assertJsonValidationErrors(['amount' => '2,349.25']);
    ($this->move)($id, 'cash_out', '1000')->assertOk()->assertJsonCount(3, 'data.movements');

    // Idempotent: the same request twice records once.
    $body = ['client_uuid' => (string) Str::uuid(), 'type' => 'cash_in', 'amount' => '10', 'reason' => 'Coins'];
    $this->postJson("/api/v1/cash-sessions/{$id}/movements", $body)->assertOk();
    $this->postJson("/api/v1/cash-sessions/{$id}/movements", $body)->assertOk()->assertJsonCount(4, 'data.movements');
});

it('closes blind: the cashier counts first and then sees over/short', function () {
    ($this->as)($this->cashier);
    $id = ($this->open)()->json('data.id');
    ($this->move)($id, 'payout', '100')->assertOk();

    // A cash sale of ₱350 and a GCash sale of ₱200 were paid into this shift (posted by the POS in 5B).
    $this->asTenant($this->tenant, function () use ($id) {
        $methods = PaymentMethod::query()->pluck('id', 'type');
        foreach ([['cash', '350.00'], ['gcash', '200.00']] as [$type, $amount]) {
            DB::table('payments')->insert([
                'tenant_id' => $this->tenant->id, 'client_uuid' => (string) Str::uuid(), 'payment_method_id' => $methods[$type],
                'cash_register_session_id' => $id, 'direction' => 'in', 'amount' => $amount, 'status' => 'confirmed', 'paid_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    // While open, the cashier does not see the expected cash...
    $this->getJson("/api/v1/cash-sessions/{$id}")->assertOk()
        ->assertJsonPath('data.expected_cash', null)
        ->assertJsonMissingPath('data.summary.expected_cash')
        ->assertJsonPath('data.summary.cash_sales', '350.00');

    // ...the manager does: 2000 + 350 - 100 = 2250.
    ($this->as)($this->manager);
    $this->getJson("/api/v1/cash-sessions/{$id}")->assertOk()->assertJsonPath('data.summary.expected_cash', '2250.00');

    ($this->as)($this->cashier);
    $closed = $this->postJson("/api/v1/cash-sessions/{$id}/close", [
        'denominations' => ['1000' => 2, '100' => 2, '20c' => 2, '0.25' => 3, '5' => 0],
        'notes' => 'End of day',
    ])->assertOk();

    // Counted 2000 + 200 + 40 + 0.75 = 2240.75 → short by 9.25.
    expect($closed->json('data.counted_cash'))->toBe('2240.75')
        ->and($closed->json('data.expected_cash'))->toBe('2250.00')
        ->and($closed->json('data.variance'))->toBe('-9.25')
        ->and($closed->json('data.denominations'))->toBe(['1000' => 2, '100' => 2, '20c' => 2, '0.25' => 3])
        ->and(collect($closed->json('data.summary.payments_by_method'))->pluck('amount', 'type')->all())->toBe(['cash' => '350.00', 'gcash' => '200.00']);

    expect(AuditLog::query()->where('event', 'cash_session.closed')->exists())->toBeTrue();
    ($this->move)($id, 'cash_in', '1')->assertStatus(409)->assertJsonPath('code', 'SESSION_CLOSED');
    $this->postJson("/api/v1/cash-sessions/{$id}/close", ['counted_cash' => '1'])->assertStatus(409);

    $this->asTenant($this->tenant, function () use ($id) {
        expect(fn () => CashRegisterSession::query()->findOrFail($id)->forceFill(['variance' => '0'])->save())->toThrow(LogicException::class);
    });
});

it('keeps shifts private to their cashier, and lets a manager close a forgotten one', function () {
    ($this->as)($this->cashier);
    $id = ($this->open)()->json('data.id');

    ($this->as)($this->cashier2);
    $this->getJson("/api/v1/cash-sessions/{$id}")->assertNotFound();
    $this->postJson("/api/v1/cash-sessions/{$id}/close", ['counted_cash' => '2000'])->assertNotFound();
    $this->getJson('/api/v1/cash-sessions')->assertOk()->assertJsonCount(0, 'data');

    ($this->as)($this->manager);
    $this->getJson('/api/v1/cash-sessions?status=open')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/cash-sessions/{$id}/close", ['counted_cash' => '2000'])->assertOk()
        ->assertJsonPath('data.variance', '0.00')
        ->assertJsonPath('data.closed_by.id', $this->manager->id);

    // The terminal is free again.
    ($this->as)($this->cashier2);
    ($this->open)()->assertCreated();
});

it('rejects unknown denominations and needs a count', function () {
    ($this->as)($this->cashier);
    $id = ($this->open)()->json('data.id');
    $this->postJson("/api/v1/cash-sessions/{$id}/close", ['denominations' => ['3' => 1]])
        ->assertUnprocessable()->assertJsonValidationErrors('denominations');
    $this->postJson("/api/v1/cash-sessions/{$id}/close", [])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
});
