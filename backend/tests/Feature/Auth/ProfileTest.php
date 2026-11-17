<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
});

it('updates the own name and phone', function () {
    $this->actingAsApi($this->owner)->putJson('/api/v1/auth/profile', ['name' => 'Arnel Santos', 'phone' => '09181112222'])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Arnel Santos')
        ->assertJsonPath('data.user.phone', '+639181112222');

    expect(AuditLog::query()->where('event', 'user.profile_updated')->where('auditable_id', $this->owner->id)->exists())->toBeTrue();
});

it('validates the profile', function () {
    $this->actingAsApi($this->owner)->putJson('/api/v1/auth/profile', ['name' => '', 'phone' => '12345'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
});

it('changes the password, keeps the current session and signs out the others', function () {
    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();
    $otherToken = $this->owner->createToken('old phone')->plainTextToken;
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->owner->id, 'payload' => '', 'last_activity' => time()]);

    $this->withHeader('Origin', 'https://app.avantpos.ph')->putJson('/api/v1/auth/password', [
        'current_password' => TestCase::PASSWORD,
        'password' => 'NewSecret456',
        'password_confirmation' => 'NewSecret456',
    ])->assertOk();

    expect(Hash::check('NewSecret456', $this->owner->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'auth.password_changed')->exists())->toBeTrue();

    // Still signed in here; the other device's API token is gone.
    $this->withHeader('Origin', 'https://app.avantpos.ph')->getJson('/api/v1/auth/me')->assertOk();

    [$tokenId] = explode('|', $otherToken);
    expect(DB::table('personal_access_tokens')->where('id', $tokenId)->exists())->toBeFalse();
});

it('requires the correct current password and a new, confirmed password', function () {
    $this->actingAsApi($this->owner)->putJson('/api/v1/auth/password', [
        'current_password' => 'wrong',
        'password' => TestCase::PASSWORD,
        'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
});
