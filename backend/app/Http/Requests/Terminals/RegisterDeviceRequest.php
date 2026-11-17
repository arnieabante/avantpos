<?php

namespace App\Http\Requests\Terminals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'sunmi', 'other'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_label' => ['required', 'string', 'max:120'],
            'device_platform' => ['nullable', 'string', Rule::in(self::PLATFORMS)],
        ];
    }
}
