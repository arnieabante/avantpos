<?php

namespace App\Http\Requests\Settings;

use App\Models\ReceiptTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $layoutKeys = array_keys(ReceiptTemplate::LAYOUT_OPTIONS);

        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'paper' => ['required', 'string', Rule::in(ReceiptTemplate::PAPERS)],
            'header_text' => ['nullable', 'string', 'max:500'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'show_logo' => ['sometimes', 'boolean'],
            'layout' => ['nullable', 'array:'.implode(',', $layoutKeys)],
            'layout.*' => ['boolean'],
        ];
    }
}
