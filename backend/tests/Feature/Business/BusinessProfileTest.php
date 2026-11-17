<?php

use App\Models\AuditLog;
use App\Models\TaxRate;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function businessPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Arnel Motoshop',
        'business_type' => 'motor_shop',
        'registered_name' => 'Arnel Santos',
        'business_style' => 'Arnel Motoshop',
        'tin' => '123456789',
        'tin_branch_code' => '00000',
        'rdo_code' => '039',
        'vat_registered' => false,
        'email' => 'Hello@Motoshop.PH',
        'phone' => '0917 123 4567',
        'address_line' => '12 Rizal St.',
        'region_code' => 'T-R1',
        'province_code' => 'T-P1',
        'city_code' => 'T-C1',
        'barangay_code' => 'T-B1',
        'postal_code' => '1100',
        'timezone' => 'Asia/Manila',
        'date_format' => 'm/d/Y',
        'time_format' => 'H:i',
    ];
}

beforeEach(function () {
    seedPsgcFixture();
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
});

it('shows the business profile with defaults', function () {
    $this->actingAsApi($this->owner)->getJson('/api/v1/business')
        ->assertOk()
        ->assertJsonPath('data.id', $this->tenant->id)
        ->assertJsonPath('data.currency', 'PHP')
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.date_format', 'M d, Y')
        ->assertJsonPath('data.tin_branch_code', '00000');
});

it('updates the profile, normalises TIN/phone/e-mail, formats the address and audits the change', function () {
    $this->actingAsApi($this->owner)->putJson('/api/v1/business', businessPayload())
        ->assertOk()
        ->assertJsonPath('data.tin', '123-456-789')
        ->assertJsonPath('data.phone', '+639171234567')
        ->assertJsonPath('data.email', 'hello@motoshop.ph')
        ->assertJsonPath('data.date_format', 'm/d/Y')
        ->assertJsonPath('data.address.city_name', 'Test City')
        ->assertJsonPath('data.address.formatted', '12 Rizal St., Brgy. Poblacion, Test City, Test Province, 1100');

    $log = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event', 'business.updated')->latest('id')->first();
    expect($log->new_values)->toHaveKey('tin', '123-456-789');
    expect(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event', 'business.settings_updated')->exists())->toBeTrue();
});

it('accepts a city without province (NCR / independent cities)', function () {
    $this->actingAsApi($this->owner)
        ->putJson('/api/v1/business', businessPayload(['province_code' => null, 'city_code' => 'T-C2', 'barangay_code' => 'T-B2']))
        ->assertOk()
        ->assertJsonPath('data.address.province_code', null)
        ->assertJsonPath('data.address.barangay_name', 'San Roque');
});

it('rejects an address whose parts do not belong together', function () {
    $this->actingAsApi($this->owner)
        ->putJson('/api/v1/business', businessPayload(['barangay_code' => 'T-B2']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['barangay_code']);

    $this->actingAsApi($this->owner)
        ->putJson('/api/v1/business', businessPayload(['city_code' => 'T-C2', 'barangay_code' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['city_code']);
});

it('validates TIN, timezone and formats', function () {
    $this->actingAsApi($this->owner)
        ->putJson('/api/v1/business', businessPayload(['tin' => '12345', 'timezone' => 'Mars/Base', 'date_format' => 'Y', 'tin_branch_code' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tin', 'timezone', 'date_format', 'tin_branch_code']);
});

it('switches the default tax rate when VAT registration changes', function () {
    $this->actingAsApi($this->owner)->putJson('/api/v1/business', businessPayload(['vat_registered' => true]))->assertOk();

    $defaults = $this->asTenant($this->tenant, fn () => TaxRate::query()->where('is_default', true)->pluck('type')->all());
    expect($defaults)->toBe(['vat']);

    $this->actingAsApi($this->owner)->putJson('/api/v1/business', businessPayload(['vat_registered' => false]))->assertOk();
    $defaults = $this->asTenant($this->tenant, fn () => TaxRate::query()->where('is_default', true)->pluck('type')->all());
    expect($defaults)->toBe(['percentage_tax']);
});

it('rejects a subdomain used by another business but keeps its own', function () {
    ['tenant' => $other] = $this->createTenant();
    $other->forceFill(['subdomain' => 'takenshop'])->save();
    $this->tenant->forceFill(['subdomain' => 'myshop'])->save();

    $this->actingAsApi($this->owner)->putJson('/api/v1/business', businessPayload(['subdomain' => 'takenshop']))
        ->assertUnprocessable()->assertJsonValidationErrors(['subdomain']);

    $this->actingAsApi($this->owner)->putJson('/api/v1/business', businessPayload(['subdomain' => 'myshop']))
        ->assertOk()->assertJsonPath('data.subdomain', 'myshop');
});

it('uploads, replaces and removes the logo on the media disk', function () {
    Storage::fake('public');
    config(['avantpos.app.media_disk' => 'public']);

    $first = $this->actingAsApi($this->owner)
        ->postJson('/api/v1/business/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])
        ->assertOk()->json('data.logo_url');

    $firstPath = Tenant::query()->find($this->tenant->id)->logo_path;
    expect($first)->not->toBeNull()
        ->and($firstPath)->toStartWith("tenants/{$this->tenant->id}/logo/")
        ->and($firstPath)->not->toContain('logo.png');
    Storage::disk('public')->assertExists($firstPath);

    $this->actingAsApi($this->owner)
        ->postJson('/api/v1/business/logo', ['logo' => UploadedFile::fake()->image('new.jpg', 200, 200)])->assertOk();
    Storage::disk('public')->assertMissing($firstPath);

    $this->actingAsApi($this->owner)->deleteJson('/api/v1/business/logo')->assertOk()->assertJsonPath('data.logo_url', null);
    expect(Tenant::query()->find($this->tenant->id)->logo_path)->toBeNull();
});

it('rejects non-image and SVG logos', function () {
    Storage::fake('public');

    $this->actingAsApi($this->owner)
        ->postJson('/api/v1/business/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
        ->assertUnprocessable()->assertJsonValidationErrors(['logo']);

    $this->actingAsApi($this->owner)
        ->postJson('/api/v1/business/logo', ['logo' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')])
        ->assertUnprocessable()->assertJsonValidationErrors(['logo']);
});

it('lets managers view but not edit the business', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager');

    $this->actingAsApi($manager)->getJson('/api/v1/business')->assertOk();
    $this->actingAsApi($manager)->putJson('/api/v1/business', businessPayload())->assertForbidden();

    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier)->getJson('/api/v1/business')->assertForbidden();
});

it('serves PSGC lookups for address pickers', function () {
    $this->actingAsApi($this->owner);

    expect(collect($this->getJson('/api/v1/ph/regions')->assertOk()->json('data'))->pluck('code'))->toContain('T-R1');
    $this->getJson('/api/v1/ph/provinces?region_code=T-R1')->assertOk()->assertJsonPath('data.0.code', 'T-P1');
    $this->getJson('/api/v1/ph/cities?province_code=T-P1')->assertOk()->assertJsonPath('data.0.code', 'T-C1');
    $this->getJson('/api/v1/ph/cities?region_code=T-R1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'T-C2');
    $this->getJson('/api/v1/ph/barangays?city_code=T-C1')->assertOk()->assertJsonPath('data.0.name', 'Poblacion');
});
