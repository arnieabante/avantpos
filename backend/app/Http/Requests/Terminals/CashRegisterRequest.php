<?php

namespace App\Http\Requests\Terminals;

use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;

class CashRegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', TenantExists::on('branches')->where('is_active', true)],
            'terminal_id' => [
                'nullable', 'integer',
                TenantExists::on('terminals')->where('branch_id', $this->integer('branch_id'))->where('status', 'active'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.exists' => 'Select an active branch.',
            'terminal_id.exists' => 'Select an active terminal of the same branch.',
        ];
    }
}
