<?php

namespace App\Http\Requests\Users;

use App\Rules\PhilippinePhoneNumber;
use App\Rules\TenantExists;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Owner / administrator adds a user directly (with a starting password) instead of inviting. */
class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', new PhilippinePhoneNumber(mobileOnly: true)],
            // Roles are tenant-owned but not tenant-scoped models (spatie): check tenant_id explicitly.
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->requireId())],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', TenantExists::on('branches')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
