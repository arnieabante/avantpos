<?php

namespace App\Http\Requests\Access;

use App\Services\Access\PermissionCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) or update (PUT/PATCH) a tenant role.
 */
class RoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = app(TenantContext::class)->requireId();
        $roleId = $this->route('role');

        return [
            'name' => [
                $creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:100',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('guard_name', app(PermissionCatalog::class)->guard())
                    ->ignore($creating ? null : $roleId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', Rule::in(app(PermissionCatalog::class)->names())],
        ];
    }
}
