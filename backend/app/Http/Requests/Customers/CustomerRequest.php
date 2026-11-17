<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Rules\PhilippinePhoneNumber;
use App\Rules\TenantExists;
use App\Services\Billing\PlanGate;
use App\Support\PhilippinePhone;
use App\Support\PhilippineTin;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->merge([
            'phone' => PhilippinePhone::normalize($this->phone),
            'tin' => PhilippineTin::normalize($this->tin),
            'email' => ($e = $trim($this->email)) ? strtolower($e) : null,
            'code' => ($c = $trim($this->code)) ? strtoupper($c) : null,
            // ID numbers are compared and printed as typed, minus spaces.
            'sc_pwd_id_no' => ($id = $trim($this->sc_pwd_id_no)) ? preg_replace('/\s+/', '', $id) : null,
            'sc_pwd_id_type' => $trim($this->sc_pwd_id_type),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');
        $tenantId = app(TenantContext::class)->requireId();
        // Credit settings need the plan feature and the collections permission.
        $credit = app(PlanGate::class)->hasFeature('credit_sales') && $this->user()?->can('customers.credit_manage');

        return [
            'customer_group_id' => ['nullable', 'integer', TenantExists::on('customer_groups')],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'code')->where('tenant_id', $tenantId)->ignore($customer?->id)],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber, Rule::unique('customers', 'phone')->where('tenant_id', $tenantId)->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'tin' => ['nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'sc_pwd_id_type' => ['nullable', Rule::in(Customer::SC_PWD_TYPES), 'required_with:sc_pwd_id_no'],
            'sc_pwd_id_no' => ['nullable', 'string', 'max:40', 'required_with:sc_pwd_id_type'],
            'allow_credit' => [$credit ? 'sometimes' : 'prohibited', 'boolean'],
            'credit_limit' => [$credit ? 'sometimes' : 'prohibited', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'Another customer already uses this mobile number.',
            'code.unique' => 'Another customer already uses this code.',
            'tin.regex' => 'Enter a 9-digit TIN, e.g. 123-456-789.',
            'sc_pwd_id_type.required_with' => 'Choose Senior Citizen or PWD.',
            'sc_pwd_id_no.required_with' => 'Enter the ID number.',
            'allow_credit.prohibited' => 'You are not allowed to change credit settings.',
            'credit_limit.prohibited' => 'You are not allowed to change credit settings.',
        ];
    }
}
