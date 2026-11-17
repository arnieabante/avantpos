<?php

namespace App\Http\Requests\Branches;

use App\Http\Requests\Concerns\ValidatesPhilippineAddress;
use App\Models\Branch;
use App\Rules\PhilippinePhoneNumber;
use App\Support\PhilippinePhone;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BranchRequest extends FormRequest
{
    use ValidatesPhilippineAddress;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected function prepareForValidation(): void
    {
        // Only touch fields that were sent, so omitted fields are left unchanged.
        $this->merge(array_map(fn (callable $normalize) => $normalize(), array_filter([
            'code' => fn () => is_string($this->code) ? strtoupper(trim($this->code)) : $this->code,
            'phone' => fn () => PhilippinePhone::normalize($this->phone),
            'email' => fn () => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
        ], fn ($key) => $this->has($key), ARRAY_FILTER_USE_KEY)));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Branch|null $branch */
        $branch = $this->route('branch');

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->ignore($branch?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            ...$this->addressRules(),
            'bir_branch_code' => ['nullable', 'string', 'regex:/^\d{3,5}$/'],
            'operating_hours' => ['nullable', 'array:'.implode(',', self::DAYS)],
            'operating_hours.*' => ['array:closed,open,close'],
            'operating_hours.*.closed' => ['required', 'boolean'],
            'operating_hours.*.open' => ['nullable', 'required_if_declined:operating_hours.*.closed', 'date_format:H:i'],
            'operating_hours.*.close' => ['nullable', 'required_if_declined:operating_hours.*.closed', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            $this->addressHierarchyCheck(),
            function (Validator $validator) {
                foreach ((array) $this->input('operating_hours', []) as $day => $hours) {
                    $open = $hours['open'] ?? null;
                    $close = $hours['close'] ?? null;
                    if (empty($hours['closed']) && $open && $close && $open === $close) {
                        $validator->errors()->add("operating_hours.{$day}.close", 'Opening and closing time cannot be the same.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers and dashes only, e.g. MAIN or QC-01.',
            'code.unique' => 'Another branch already uses this code.',
            'bir_branch_code.regex' => 'The BIR branch code must be 3 to 5 digits, e.g. 00001.',
            'operating_hours.*.open.required_if_declined' => 'Enter the opening time.',
            'operating_hours.*.close.required_if_declined' => 'Enter the closing time.',
        ];
    }
}
