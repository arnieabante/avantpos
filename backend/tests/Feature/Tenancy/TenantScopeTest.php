<?php

use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use App\Rules\TenantExists;
use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;

it('only returns records of the current tenant', function () {
    ['tenant' => $a] = $this->createTenant();
    ['tenant' => $b] = $this->createTenant();
    $this->createTerminal($a);
    $this->createTerminal($b);

    $this->asTenant($a, function () use ($a) {
        expect(Terminal::query()->pluck('tenant_id')->unique()->all())->toBe([$a->id])
            ->and(Branch::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(1);
    });
});

it('throws instead of running an unscoped query when no tenant is set', function () {
    $this->createTenant();
    app(TenantContext::class)->forget();

    Branch::query()->count();
})->throws(MissingTenantContextException::class);

it('cannot create tenant data without a tenant context', function () {
    app(TenantContext::class)->forget();

    Branch::query()->create(['code' => 'X', 'name' => 'X']);
})->throws(MissingTenantContextException::class);

it('always stamps the context tenant on create, ignoring a forged tenant_id', function () {
    ['tenant' => $a] = $this->createTenant();
    ['tenant' => $b] = $this->createTenant();

    $branch = $this->asTenant($a, function () use ($b) {
        $branch = new Branch(['code' => 'B2', 'name' => 'Second']);
        $branch->tenant_id = $b->id; // forged

        $branch->save();

        return $branch;
    });

    expect($branch->tenant_id)->toBe($a->id);
});

it('forbids moving a record to another tenant', function () {
    ['tenant' => $a, 'branch' => $branch] = $this->createTenant();
    ['tenant' => $b] = $this->createTenant();

    $this->asTenant($a, function () use ($branch, $b) {
        $branch->tenant_id = $b->id;
        $branch->save();
    });
})->throws(LogicException::class);

it('finds another tenant record only through an explicit bypass', function () {
    ['tenant' => $a] = $this->createTenant();
    ['tenant' => $b, 'branch' => $foreign] = $this->createTenant();

    $this->asTenant($a, function () use ($foreign) {
        expect(Branch::query()->find($foreign->id))->toBeNull()
            ->and(Branch::withoutTenantScope()->find($foreign->id))->not->toBeNull();
    });
});

it('fails validation for ids of another tenant (TenantExists)', function () {
    ['tenant' => $a, 'branch' => $own] = $this->createTenant();
    ['branch' => $foreign] = $this->createTenant();

    $this->asTenant($a, function () use ($own, $foreign) {
        $rules = ['branch_id' => [TenantExists::on('branches')]];

        expect(Validator::make(['branch_id' => $own->id], $rules)->passes())->toBeTrue()
            ->and(Validator::make(['branch_id' => $foreign->id], $rules)->fails())->toBeTrue();
    });
});

it('restores the previous tenant after runAs', function () {
    ['tenant' => $a] = $this->createTenant();
    ['tenant' => $b] = $this->createTenant();
    $context = app(TenantContext::class);

    $context->runAs($a, function () use ($context, $a, $b) {
        $context->runAs($b, fn () => expect($context->id())->toBe($b->id));
        expect($context->id())->toBe($a->id);
    });

    expect($context->check())->toBeFalse();
});
