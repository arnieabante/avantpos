<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Services\Customers\LoyaltyService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltySettingsController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function show(): JsonResponse
    {
        return ApiResponse::data($this->loyalty->settings());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'spend_per_point' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:1000000'],
            'point_value' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'min_redeem_points' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [
            'spend_per_point.min' => 'Customers must spend at least ₱1.00 per point.',
        ]);

        return ApiResponse::data($this->loyalty->saveSettings($data));
    }
}
