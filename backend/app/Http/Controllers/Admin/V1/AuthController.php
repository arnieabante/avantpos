<?php

namespace App\Http\Controllers\Admin\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use App\Support\Security\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * SaaS platform administrator authentication (separate "platform" guard),
 * with optional two-factor sign-in (authenticator app codes).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        if (! $request->hasSession()) {
            throw new DomainException('Platform admin login requires the AvantPOS web app.', 'SESSION_UNAVAILABLE', 400);
        }

        $email = strtolower($credentials['email']);
        $guard = Auth::guard('platform');
        if (! $guard->validate(['email' => $email, 'password' => $credentials['password'], 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var PlatformAdmin $admin */
        $admin = PlatformAdmin::query()->where('email', $email)->firstOrFail();
        if ($admin->two_factor_confirmed_at) {
            if (blank($credentials['code'] ?? null)) {
                throw new DomainException('Enter the 6-digit code from your authenticator app.', 'TWO_FACTOR_REQUIRED', 422);
            }
            if (! Totp::verify((string) $admin->two_factor_secret, (string) $credentials['code'])) {
                $this->audit->log('platform.login_2fa_failed', $admin, actor: $admin);
                throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
            }
        }

        $guard->login($admin);
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('platform.login', $admin, actor: $admin);

        return ApiResponse::data($this->payload($admin));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::data($this->payload($this->current($request)));
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user('platform');
        $this->audit->log('platform.logout', $admin, actor: $admin);

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::message('Logged out.');
    }

    /**
     * Start turning on two-factor sign-in: a new secret to add to an
     * authenticator app (not active until confirmed with a code).
     */
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $admin = $this->current($request);
        if ($admin->two_factor_confirmed_at) {
            throw new DomainException('Two-factor sign-in is already on.', 'TWO_FACTOR_ON', 409);
        }
        $secret = Totp::secret();
        $admin->forceFill(['two_factor_secret' => $secret])->save();

        return ApiResponse::data(['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $admin->email)]);
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        $admin = $this->current($request);
        if (! $admin->two_factor_secret || ! Totp::verify((string) $admin->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->audit->log('platform.2fa_enabled', $admin, actor: $admin);

        return ApiResponse::data($this->payload($admin));
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);
        $admin = $this->current($request);
        if (! Hash::check($data['password'], $admin->password) || ! Totp::verify((string) $admin->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The password or code is not valid.']);
        }
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('platform.2fa_disabled', $admin, actor: $admin);

        return ApiResponse::data($this->payload($admin));
    }

    /** Change your own password (required at first sign-in when someone else set it). */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);
        $admin = $this->current($request);
        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages(['current_password' => 'The password is incorrect.']);
        }
        $admin->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $this->audit->log('platform.password_changed', $admin, actor: $admin);

        return ApiResponse::data($this->payload($admin->fresh()));
    }

    /** The signed-in admin, freshly read (two-factor columns included). */
    private function current(Request $request): PlatformAdmin
    {
        return PlatformAdmin::query()->findOrFail($request->user('platform')->getAuthIdentifier());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'two_factor' => $admin->two_factor_confirmed_at !== null,
            'must_change_password' => (bool) $admin->must_change_password,
        ];
    }
}
