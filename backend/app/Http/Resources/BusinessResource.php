<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Business\BusinessProfileService;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;

/**
 * GET /api/v1/business — the tenant's business profile and regional settings.
 *
 * @property Tenant $resource
 */
class BusinessResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->resource;
        $settings = app(BusinessProfileService::class)->generalSettings();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'business_type' => $tenant->business_type,
            'subdomain' => $tenant->subdomain,
            'status' => $tenant->status->value,
            'registered_name' => $tenant->registered_name,
            'business_style' => $tenant->business_style,
            'tin' => $tenant->tin,
            'tin_branch_code' => $tenant->tin_branch_code,
            'rdo_code' => $tenant->rdo_code,
            'vat_registered' => $tenant->vat_registered,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'address' => PhAddress::from($tenant),
            'logo_url' => app(MediaStorage::class)->url($tenant->logo_path),
            'timezone' => $tenant->timezone,
            'currency' => $tenant->currency,
            'date_format' => $settings['date_format'],
            'time_format' => $settings['time_format'],
            'updated_at' => $tenant->updated_at?->toIso8601String(),
        ];
    }
}
