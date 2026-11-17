<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * @property Role $resource
 */
class RoleResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'is_system' => $this->resource->is_system,
            'is_locked' => $this->resource->is_locked,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->resource->permissions->pluck('name')->sort()->values()),
            'users_count' => $this->when(isset($this->resource->users_count), fn () => $this->resource->users_count),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
