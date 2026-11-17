<?php

use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('sends a reset link pointing to the SPA without revealing whether the account exists', function () {
    Notification::fake();
    ['owner' => $owner] = $this->createTenant();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $owner->email])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    Notification::assertSentTo($owner, QueuedResetPassword::class, function (QueuedResetPassword $n) use ($owner) {
        $url = $n->toMail($owner)->actionUrl;

        return str_starts_with($url, 'https://app.avantpos.ph/reset-password?token=');
    });
    Notification::assertSentTimes(QueuedResetPassword::class, 1);
});

it('resets the password with a valid token and revokes API tokens', function () {
    Notification::fake();
    ['owner' => $owner] = $this->createTenant();
    $owner->createToken('phone');

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $owner->email]);

    $token = null;
    Notification::assertSentTo($owner, QueuedResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $owner->email,
        'password' => 'NewSecret456',
        'password_confirmation' => 'NewSecret456',
    ])->assertOk();

    expect(Hash::check('NewSecret456', $owner->fresh()->password))->toBeTrue()
        ->and($owner->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'invalid',
        'email' => $owner->email,
        'password' => 'NewSecret456',
        'password_confirmation' => 'NewSecret456',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('verifies the e-mail address through the signed link', function () {
    ['owner' => $owner] = $this->createTenant();
    $owner->forceFill(['email_verified_at' => null])->save();

    $url = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
        'id' => $owner->id,
        'hash' => sha1($owner->email),
    ]);

    $this->getJson($url)->assertOk();
    expect($owner->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects tampered verification links', function () {
    ['owner' => $owner] = $this->createTenant();

    $url = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
        'id' => $owner->id,
        'hash' => sha1($owner->email),
    ]);

    $this->getJson($url.'x')->assertForbidden();
});

it('builds verification links to the SPA', function () {
    Notification::fake();
    ['owner' => $owner] = $this->createTenant();
    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAsApi($owner)->postJson('/api/v1/auth/email/verification-notification')->assertOk();

    Notification::assertSentTo($owner, QueuedVerifyEmail::class, fn ($n) => str_starts_with(
        $n->toMail($owner)->actionUrl, 'https://app.avantpos.ph/verify-email?url='
    ));
});

it('blocks unverified users from protected endpoints', function () {
    ['owner' => $owner] = $this->createTenant();
    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAsApi($owner)->getJson('/api/v1/roles')
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    // /me stays available so the SPA can show the "verify your e-mail" screen.
    $this->actingAsApi($owner)->getJson('/api/v1/auth/me')->assertOk();
});
