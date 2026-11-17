<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Rules\PhilippinePhoneNumber;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use App\Support\PhilippinePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user's own profile and password ("My account").
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): AccountResource
    {
        $request->merge(['phone' => PhilippinePhone::normalize($request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber(mobileOnly: true)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->fill($data)->save();
        $this->audit->logChanges('user.profile_updated', $user);

        return new AccountResource($user);
    }

    /**
     * Changes the password and signs out every OTHER session and API token.
     */
    public function password(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            $user->forceFill(['password' => $request->string('password')->toString()])->save();

            $currentToken = $user->currentAccessToken();
            $user->tokens()
                ->when($currentToken && method_exists($currentToken, 'getKey'), fn ($q) => $q->whereKeyNot($currentToken->getKey()))
                ->delete();

            DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
                ->delete();

            $this->audit->log('auth.password_changed', $user);
        });

        if ($request->hasSession()) {
            // New session id; the old one is destroyed.
            $request->session()->regenerate(true);
        }

        return ApiResponse::message('Your password has been changed. Other devices have been signed out.');
    }
}
