<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Requests\Business\UploadLogoRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Tenant;
use App\Services\Business\BusinessProfileService;
use App\Tenancy\TenantContext;

/**
 * The signed-in user's own business. There is no tenant id in the URL:
 * the business is always the one resolved from the authenticated user.
 */
class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessProfileService $profile,
        private readonly TenantContext $context,
    ) {}

    public function show(): BusinessResource
    {
        return new BusinessResource($this->tenant());
    }

    public function update(UpdateBusinessRequest $request): BusinessResource
    {
        return new BusinessResource($this->profile->update($this->tenant(), $request->validated()));
    }

    public function uploadLogo(UploadLogoRequest $request): BusinessResource
    {
        return new BusinessResource($this->profile->replaceLogo($this->tenant(), $request->file('logo')));
    }

    public function deleteLogo(): BusinessResource
    {
        return new BusinessResource($this->profile->removeLogo($this->tenant()));
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->context->requireId());
    }
}
