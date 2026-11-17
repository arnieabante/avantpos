<?php

namespace App\Http\Requests\Terminals;

use App\Models\Terminal;
use App\Rules\TenantExists;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TerminalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Terminal|null $terminal */
        $terminal = $this->route('terminal');

        return [
            'branch_id' => ['required', 'integer', TenantExists::on('branches')->where('is_active', true)],
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('terminals', 'code')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->ignore($terminal?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:80'],

            // BIR machine registration (optional until the business applies for a PTU)
            'min' => ['nullable', 'string', 'max:30'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'ptu_no' => ['nullable', 'string', 'max:60'],
            'ptu_issued_at' => ['nullable', 'date'],
            'ptu_valid_until' => ['nullable', 'date', 'after_or_equal:ptu_issued_at'],

            // Create a cash drawer together with a new terminal (store only).
            'create_cash_register' => [$terminal ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.exists' => 'Select an active branch.',
            'code.regex' => 'Use letters, numbers and dashes only, e.g. POS-01.',
            'code.unique' => 'Another terminal already uses this code.',
        ];
    }
}
