<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SetPinRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed', 'not_in:0000,1111,1234,123456,000000,111111'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.regex' => 'The PIN must be 4 to 6 digits.',
            'pin.not_in' => 'Please choose a less predictable PIN.',
        ];
    }
}
