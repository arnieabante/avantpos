<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Supplier;
use App\Rules\PhilippinePhoneNumber;
use App\Support\PhilippinePhone;
use App\Support\PhilippineTin;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tin' => PhilippineTin::normalize($this->tin),
            'phone' => PhilippinePhone::normalize($this->phone),
            'email' => is_string($this->email) && trim($this->email) !== '' ? strtolower(trim($this->email)) : null,
            'code' => is_string($this->code) && trim($this->code) !== '' ? strtoupper(trim($this->code)) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->route('supplier');

        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('suppliers', 'code')
                ->where('tenant_id', app(TenantContext::class)->requireId())->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber],
            'email' => ['nullable', 'email', 'max:150'],
            'tin' => ['nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'vat_registered' => ['required', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another supplier already uses this code.',
            'tin.regex' => 'Enter a 9-digit TIN, e.g. 123-456-789.',
        ];
    }
}
