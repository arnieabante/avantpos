<?php

namespace App\Http\Requests\Users;

use App\Rules\PhilippinePhoneNumber;
use App\Rules\TenantExists;
use App\Support\PhilippinePhone;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhilippinePhone::normalize($this->phone)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', new PhilippinePhoneNumber(mobileOnly: true)],
            // Roles are tenant-owned but not tenant-scoped models (spatie): check tenant_id explicitly.
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->requireId())],
            'branch_ids' => ['sometimes', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', TenantExists::on('branches')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['branch_ids.min' => 'Assign at least one branch.'];
    }
}
