<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhilippinePhoneNumber;
use App\Support\PhilippinePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhilippinePhone::normalize($this->phone)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber(mobileOnly: true)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
