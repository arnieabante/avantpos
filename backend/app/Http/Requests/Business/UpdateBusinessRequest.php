<?php

namespace App\Http\Requests\Business;

use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Http\Requests\Concerns\ValidatesPhilippineAddress;
use App\Rules\AvailableSubdomain;
use App\Rules\PhilippinePhoneNumber;
use App\Services\Business\BusinessProfileService;
use App\Support\PhilippinePhone;
use App\Support\PhilippineTin;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    use ValidatesPhilippineAddress;

    protected function prepareForValidation(): void
    {
        // Only touch fields that were sent, so omitted fields are left unchanged.
        $this->merge(array_map(fn (callable $normalize) => $normalize(), array_filter([
            'tin' => fn () => PhilippineTin::normalize($this->tin),
            'phone' => fn () => PhilippinePhone::normalize($this->phone),
            'email' => fn () => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'subdomain' => fn () => is_string($this->subdomain) && trim($this->subdomain) !== '' ? strtolower(trim($this->subdomain)) : null,
        ], fn ($key) => $this->has($key), ARRAY_FILTER_USE_KEY)));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'business_type' => ['nullable', 'string', Rule::in(RegisterTenantRequest::BUSINESS_TYPES)],
            'subdomain' => ['nullable', 'string', new AvailableSubdomain(app(TenantContext::class)->id())],

            // BIR registration
            'registered_name' => ['nullable', 'string', 'max:200'],
            'business_style' => ['nullable', 'string', 'max:200'],
            'tin' => ['nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'tin_branch_code' => ['required', 'string', 'regex:/^\d{3,5}$/'],
            'rdo_code' => ['nullable', 'string', 'regex:/^\d{2,3}[A-Z]?$/'],
            'vat_registered' => ['required', 'boolean'],

            // Contact & address
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber],
            ...$this->addressRules(),

            // Regional settings
            'timezone' => ['required', 'string', 'timezone:all'],
            'date_format' => ['required', 'string', Rule::in(array_keys(BusinessProfileService::DATE_FORMATS))],
            'time_format' => ['required', 'string', Rule::in(array_keys(BusinessProfileService::TIME_FORMATS))],
        ];
    }

    public function after(): array
    {
        return [$this->addressHierarchyCheck()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tin.regex' => 'Enter a 9-digit TIN, e.g. 123-456-789.',
            'tin_branch_code.regex' => 'The TIN branch code must be 3 to 5 digits, e.g. 00000.',
            'rdo_code.regex' => 'Enter the RDO code, e.g. 039.',
            'postal_code.regex' => 'The ZIP code must be 4 digits.',
        ];
    }
}
