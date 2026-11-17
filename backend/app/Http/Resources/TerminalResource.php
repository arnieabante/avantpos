<?php

namespace App\Http\Resources;

use App\Models\Terminal;
use Illuminate\Http\Request;

/**
 * @property Terminal $resource
 */
class TerminalResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $terminal = $this->resource;

        return [
            'id' => $terminal->id,
            'branch_id' => $terminal->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $terminal->branch->id,
                'code' => $terminal->branch->code,
                'name' => $terminal->branch->name,
            ]),
            'code' => $terminal->code,
            'name' => $terminal->name,
            'status' => $terminal->status->value,
            'min' => $terminal->min,
            'serial_no' => $terminal->serial_no,
            'ptu_no' => $terminal->ptu_no,
            'ptu_issued_at' => $terminal->ptu_issued_at?->toDateString(),
            'ptu_valid_until' => $terminal->ptu_valid_until?->toDateString(),
            'device' => $terminal->device_registered_at ? [
                'label' => $terminal->device_label,
                'platform' => $terminal->device_platform,
                'registered_at' => $terminal->device_registered_at->toIso8601String(),
                'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
                'last_synced_at' => $terminal->last_synced_at?->toIso8601String(),
            ] : null,
            'cash_registers' => CashRegisterResource::collection($this->whenLoaded('cashRegisters')),
            'created_at' => $terminal->created_at?->toIso8601String(),
        ];
    }
}
