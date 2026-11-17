<?php

namespace App\Http\Resources;

use App\Models\CashRegister;
use Illuminate\Http\Request;

/**
 * @property CashRegister $resource
 */
class CashRegisterResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $register = $this->resource;

        return [
            'id' => $register->id,
            'branch_id' => $register->branch_id,
            'terminal_id' => $register->terminal_id,
            'name' => $register->name,
            'is_active' => $register->is_active,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $register->branch->id, 'name' => $register->branch->name]),
            'terminal' => $this->whenLoaded('terminal', fn () => $register->terminal
                ? ['id' => $register->terminal->id, 'code' => $register->terminal->code, 'name' => $register->terminal->name]
                : null),
        ];
    }
}
