<?php

namespace App\Http\Controllers\Api\V1\Terminal;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints called BY a registered POS device (device token).
 */
class TerminalSessionController extends Controller
{
    /**
     * Connectivity + identity check used by the offline POS to detect when
     * the server is reachable again. Also returns server time so the device
     * can detect clock drift (important for offline sale timestamps).
     */
    public function ping(Request $request, TenantContext $tenant): JsonResponse
    {
        /** @var Terminal $terminal */
        $terminal = $request->user();

        return ApiResponse::data([
            'terminal' => ['id' => $terminal->id, 'code' => $terminal->code, 'name' => $terminal->name],
            'branch_id' => $terminal->branch_id,
            'tenant' => ['id' => $tenant->id(), 'name' => $tenant->tenant()?->name],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
