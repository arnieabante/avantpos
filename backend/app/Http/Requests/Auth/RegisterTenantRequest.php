<?php

namespace App\Http\Requests\Auth;

use App\Rules\AvailableSubdomain;
use App\Rules\PhilippinePhoneNumber;
use App\Support\PhilippinePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterTenantRequest extends FormRequest
{
    public const BUSINESS_TYPES = [
        'sari_sari', 'grocery', 'hardware', 'motor_shop', 'apparel', 'pharmacy',
        'electronics', 'bookstore', 'general_merchandise', 'other',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'subdomain' => is_string($this->subdomain) && trim($this->subdomain) !== '' ? strtolower(trim($this->subdomain)) : null,
            'phone' => PhilippinePhone::normalize($this->phone),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'business_type' => ['nullable', 'string', Rule::in(self::BUSINESS_TYPES)],
            'subdomain' => ['nullable', 'string', new AvailableSubdomain],
            'vat_registered' => ['sometimes', 'boolean'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', new PhilippinePhoneNumber(mobileOnly: true)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This e-mail address is already registered with AvantPOS.',
            'accept_terms.accepted' => 'Please accept the Terms of Service and Privacy Policy.',
        ];
    }
}
