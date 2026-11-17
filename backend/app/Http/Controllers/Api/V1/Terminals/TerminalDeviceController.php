<?php

namespace App\Http\Controllers\Api\V1\Terminals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Terminals\RegisterDeviceRequest;
use App\Models\Terminal;
use App\Services\Terminals\TerminalDeviceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Binding a device to a POS terminal (done by a manager, from the device).
 */
class TerminalDeviceController extends Controller
{
    public function __construct(private readonly TerminalDeviceService $devices) {}

    public function store(RegisterDeviceRequest $request, Terminal $terminal): JsonResponse
    {
        $result = $this->devices->register(
            $terminal,
            $request->string('device_label')->toString(),
            $request->input('device_platform'),
        );

        return ApiResponse::data([
            // Shown ONCE: the device stores it (IndexedDB / secure storage).
            'device_token' => $result['token'],
            'terminal' => [
                'id' => $terminal->id,
                'code' => $terminal->code,
                'name' => $terminal->name,
                'branch_id' => $terminal->branch_id,
                'device_label' => $terminal->device_label,
                'device_platform' => $terminal->device_platform,
            ],
        ], 201);
    }

    public function destroy(Terminal $terminal): JsonResponse
    {
        $this->devices->revoke($terminal);

        return ApiResponse::noContent();
    }
}
