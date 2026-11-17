<?php

use App\Models\DiscountPreset;
use App\Models\PaymentMethod;
use App\Models\ReceiptTemplate;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\UserInvitation;
use App\Services\Users\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/*
 * End-to-end: a user of tenant A can never read or change tenant B data
 * through the API, whatever ids they send.
 */

beforeEach(function () {
    Notification::fake();
    ['tenant' => $this->tenantA, 'owner' => $this->ownerA] = $this->createTenant();
    ['tenant' => $this->tenantB, 'owner' => $this->ownerB, 'branch' => $this->branchB] = $this->createTenant();

    $this->roleB = $this->asTenant($this->tenantB, fn () => Role::forCurrentTenant()->where('key', 'cashier')->firstOrFail());
    $this->invitationB = $this->asTenant($this->tenantB, function () {
        $this->actingAsApi($this->ownerB);

        return app(InvitationService::class)
            ->invite($this->ownerB, ['email' => 'b-invitee@example.com', 'role_id' => $this->roleB->id, 'branch_ids' => [$this->branchB->id]]);
    });
    app(TenantContext::class)->forget();
});

it('lists only own roles', function () {
    $ids = collect($this->actingAsApi($this->ownerA)->getJson('/api/v1/roles')->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($this->roleB->id)->and($ids)->toHaveCount(6);
});

it('returns 404 for another tenant role (show / update / delete)', function () {
    $this->actingAsApi($this->ownerA);

    $this->getJson("/api/v1/roles/{$this->roleB->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $this->putJson("/api/v1/roles/{$this->roleB->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/roles/{$this->roleB->id}")->assertNotFound();

    expect($this->roleB->fresh()->name)->toBe('Cashier');
});

it('returns 404 for another tenant invitation (route model binding is scoped)', function () {
    $this->actingAsApi($this->ownerA);

    $this->deleteJson("/api/v1/invitations/{$this->invitationB->id}")->assertNotFound();
    $this->postJson("/api/v1/invitations/{$this->invitationB->id}/resend")->assertNotFound();

    expect(UserInvitation::withoutTenantScope()->find($this->invitationB->id)->revoked_at)->toBeNull();
});

it('cannot invite into another tenant role or branch', function () {
    $this->actingAsApi($this->ownerA)->postJson('/api/v1/invitations', [
        'email' => 'x@example.com',
        'role_id' => $this->roleB->id,
        'branch_ids' => [$this->branchB->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_id', 'branch_ids.0']);
});

it('cannot register a device on another tenant terminal', function () {
    $terminalB = $this->createTerminal($this->tenantB);

    $this->actingAsApi($this->ownerA)
        ->postJson("/api/v1/terminals/{$terminalB->id}/device", ['device_label' => 'Evil'])
        ->assertNotFound();
});

it('cannot select another tenant branch via X-Branch-Id', function () {
    $this->actingAsApi($this->ownerA)
        ->withHeader('X-Branch-Id', (string) $this->branchB->id)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'BRANCH_FORBIDDEN');
});

it('returns only own data in /auth/me', function () {
    $this->actingAsApi($this->ownerA)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.id', $this->tenantA->id)
        ->assertJsonCount(1, 'data.branches');
});

it('never exposes or changes another business configuration (Phase 4A endpoints)', function () {
    $b = $this->asTenant($this->tenantB, fn () => [
        'tax' => TaxRate::query()->firstOrFail(),
        'payment' => PaymentMethod::query()->where('type', 'maya')->firstOrFail(),
        'discount' => DiscountPreset::query()->firstOrFail(),
        'receipt' => ReceiptTemplate::query()->where('is_default', false)->firstOrFail(),
        'terminal' => $this->createTerminal($this->tenantB, $this->branchB),
    ]);
    app(TenantContext::class)->forget();

    $this->actingAsApi($this->ownerA);

    // Lists contain only own records.
    expect(collect($this->getJson('/api/v1/branches')->json('data'))->pluck('id'))->not->toContain($this->branchB->id)
        ->and(collect($this->getJson('/api/v1/terminals')->json('data'))->pluck('id'))->not->toContain($b['terminal']->id)
        ->and(collect($this->getJson('/api/v1/tax-rates')->json('data'))->pluck('id'))->not->toContain($b['tax']->id)
        ->and(collect($this->getJson('/api/v1/payment-methods')->json('data'))->pluck('id'))->not->toContain($b['payment']->id);

    // Direct access by id is a 404.
    $this->getJson("/api/v1/branches/{$this->branchB->id}")->assertNotFound();
    $this->putJson("/api/v1/branches/{$this->branchB->id}", ['code' => 'X', 'name' => 'Hacked'])->assertNotFound();
    $this->postJson("/api/v1/branches/{$this->branchB->id}/make-main")->assertNotFound();
    $this->postJson("/api/v1/terminals/{$b['terminal']->id}/retire")->assertNotFound();
    $this->putJson("/api/v1/tax-rates/{$b['tax']->id}", ['name' => 'X', 'type' => 'vat', 'rate' => '0'])->assertNotFound();
    $this->putJson("/api/v1/payment-methods/{$b['payment']->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->putJson("/api/v1/discount-presets/{$b['discount']->id}", ['name' => 'X', 'type' => 'percent', 'value' => '1', 'scope' => 'line'])->assertNotFound();
    $this->deleteJson("/api/v1/receipt-templates/{$b['receipt']->id}")->assertNotFound();

    // Foreign ids in bodies fail validation.
    $this->postJson('/api/v1/terminals', ['branch_id' => $this->branchB->id, 'code' => 'POS-X', 'name' => 'Sneaky'])
        ->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

    expect($this->branchB->fresh()->name)->toBe('Main Branch')
        ->and($b['terminal']->fresh()->status->value)->toBe('active')
        ->and(ReceiptTemplate::withoutTenantScope()->find($b['receipt']->id))->not->toBeNull();
});
