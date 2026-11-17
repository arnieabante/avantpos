<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\SupervisorOverrideRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SupervisorOverrideService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PinController extends Controller
{
    /**
     * Set or change the user's own POS PIN.
     */
    public function update(SetPinRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->setPin($request->string('pin')->toString());

        $audit->log('auth.pin_changed', $user);

        return ApiResponse::message('Your PIN has been saved.');
    }

    /**
     * Supervisor PIN override: returns a short-lived single-use token.
     */
    public function override(SupervisorOverrideRequest $request, SupervisorOverrideService $overrides): JsonResponse
    {
        $result = $overrides->grant(
            $request->user(),
            $request->integer('supervisor_id'),
            $request->string('pin')->toString(),
            $request->string('permission')->toString(),
        );

        return ApiResponse::data([
            'override_token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'supervisor' => ['id' => $result['supervisor']->id, 'name' => $result['supervisor']->name],
        ], 201);
    }
}
