<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * GET (signed URL from the e-mail, forwarded by the SPA). The signature is
     * the proof of ownership, so the user does not need to be logged in.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::withoutTenantScope()->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new DomainException('This verification link is invalid.', 'VERIFICATION_INVALID', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return ApiResponse::message('Your e-mail address has been verified.');
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::message('Your e-mail address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::message('A new verification link has been sent to your e-mail address.');
    }
}
