<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Login / logout for tenant users.
 *
 *  - Browser SPA:       POST /auth/login   -> session cookie (Sanctum stateful)
 *  - Native/mobile app: POST /auth/token   -> bearer token
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly AuditLogger $audit,
    ) {}

    public function login(LoginRequest $request): AccountResource
    {
        if (! $request->hasSession()) {
            throw new DomainException(
                'Cookie login is only available to the AvantPOS web app. Native apps must use /auth/token.',
                'SESSION_UNAVAILABLE',
                400,
            );
        }

        $user = $this->login->verify($request, $request->string('email')->toString(), $request->string('password')->toString());

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->login->recordLogin($user, 'session');

        return new AccountResource($user);
    }

    public function issueToken(LoginRequest $request): JsonResponse
    {
        $request->validate(['device_name' => ['required', 'string', 'max:120']]);

        $user = $this->login->verify($request, $request->string('email')->toString(), $request->string('password')->toString());
        $token = $user->createToken('app:'.$request->string('device_name')->toString(), ['*'], now()->addDays(30));

        $this->login->recordLogin($user, 'token');

        return ApiResponse::data([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'account' => (new AccountResource($user))->resolve($request),
        ], 201);
    }

    public function me(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->audit->log('auth.logout', $user, actor: $user);

        return ApiResponse::message('Logged out.');
    }
}
