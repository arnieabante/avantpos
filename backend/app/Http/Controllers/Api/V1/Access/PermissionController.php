<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Services\Access\PermissionCatalog;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Grouped permission catalogue for the role editor.
     */
    public function index(PermissionCatalog $catalog): JsonResponse
    {
        $groups = collect($catalog->groups())->map(fn (array $group, string $key) => [
            'key' => $key,
            'label' => $group['label'],
            'permissions' => collect($group['permissions'])
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
                ->values(),
        ])->values();

        return ApiResponse::data($groups);
    }
}
