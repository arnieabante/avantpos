<?php

namespace App\Http\Requests\Auth;

use App\Rules\TenantExists;
use App\Services\Access\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupervisorOverrideRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supervisor_id' => ['required', 'integer', TenantExists::on('users')],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'permission' => ['required', 'string', Rule::in(app(PermissionCatalog::class)->names())],
        ];
    }
}
