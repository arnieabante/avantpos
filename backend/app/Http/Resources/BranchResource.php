<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * @property Branch $resource
 */
class BranchResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branch = $this->resource;

        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'is_main' => $branch->is_main,
            'is_active' => $branch->is_active,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'address' => PhAddress::from($branch),
            'bir_branch_code' => $branch->bir_branch_code,
            'operating_hours' => $branch->operating_hours,
            'terminals_count' => $this->whenCounted('terminals'),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $branch->created_at?->toIso8601String(),
            'updated_at' => $branch->updated_at?->toIso8601String(),
        ];
    }
}
