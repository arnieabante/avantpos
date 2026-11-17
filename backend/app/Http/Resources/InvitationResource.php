<?php

namespace App\Http\Resources;

use App\Models\UserInvitation;
use Illuminate\Http\Request;

/**
 * @property UserInvitation $resource
 */
class InvitationResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'email' => $i->email,
            'name' => $i->name,
            'role' => $this->whenLoaded('role', fn () => ['id' => $i->role->id, 'name' => $i->role->name]),
            'branch_ids' => $i->branch_ids ?? [],
            'status' => match (true) {
                $i->accepted_at !== null => 'accepted',
                $i->revoked_at !== null => 'revoked',
                $i->expires_at->isPast() => 'expired',
                default => 'pending',
            },
            'expires_at' => $i->expires_at->toIso8601String(),
            'accepted_at' => $i->accepted_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
