<?php

namespace App\Http\Requests\Users;

use App\Rules\TenantExists;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
            'name' => ['nullable', 'string', 'max:120'],
            // Roles are tenant-owned but not tenant-scoped models (spatie), so check tenant_id explicitly.
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->requireId())],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', TenantExists::on('branches')],
        ];
    }
}
