<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Tenancy\RegisterTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Http\Resources\AccountResource;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterTenantRequest $request, RegisterTenant $register, TenantContext $context): JsonResponse
    {
        ['tenant' => $tenant, 'user' => $user] = $register->handle($request->validated());

        // The new owner acts within their tenant for the rest of this request.
        $context->set($tenant);

        // Sign the owner in straight away (browser SPA only).
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return (new AccountResource($user))->response()->setStatusCode(201);
    }
}
