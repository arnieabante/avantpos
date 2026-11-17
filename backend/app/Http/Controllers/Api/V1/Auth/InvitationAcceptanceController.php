<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\AccountResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Users\InvitationService;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Public endpoints used by the "Accept invitation" page.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->findPending($token);

        return ApiResponse::data([
            'business_name' => Tenant::query()->whereKey($invitation->tenant_id)->value('name'),
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => Role::query()->whereKey($invitation->role_id)->value('name'),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, TenantContext $context): JsonResponse
    {
        $user = $this->invitations->accept($token, $request->validated());

        // The new user acts within their tenant for the rest of this request.
        $context->set(Tenant::query()->findOrFail($user->tenant_id));

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return (new AccountResource($user))->response()->setStatusCode(201);
    }
}
