<?php

namespace App\Services\Business;

use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Business profile (the tenant record), regional settings and logo.
 */
class BusinessProfileService
{
    /** PHP date() format => example shown in the UI. */
    public const DATE_FORMATS = [
        'M d, Y' => 'Nov 14, 2026',
        'F d, Y' => 'November 14, 2026',
        'm/d/Y' => '11/14/2026',
        'd/m/Y' => '14/11/2026',
        'Y-m-d' => '2026-11-14',
    ];

    public const TIME_FORMATS = [
        'h:i A' => '02:30 PM',
        'H:i' => '14:30',
    ];

    private const GENERAL_SETTINGS_KEY = 'general';

    private const PROFILE_FIELDS = [
        'name', 'business_type', 'subdomain',
        'registered_name', 'business_style', 'tin', 'tin_branch_code', 'rdo_code', 'vat_registered',
        'email', 'phone', 'address_line', 'region_code', 'province_code', 'city_code', 'barangay_code', 'postal_code',
        'timezone',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaStorage $media,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated UpdateBusinessRequest data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $tenant->fill(Arr::only($data, self::PROFILE_FIELDS));
            $vatChanged = $tenant->isDirty('vat_registered');
            $tenant->save();

            $this->audit->logChanges('business.updated', $tenant);

            if ($vatChanged) {
                $this->switchDefaultTaxRate($tenant->vat_registered);
            }

            $this->saveGeneralSettings(Arr::only($data, ['date_format', 'time_format']));

            return $tenant->refresh();
        });
    }

    /**
     * @return array{date_format: string, time_format: string}
     */
    public function generalSettings(): array
    {
        $stored = TenantSetting::query()->where('key', self::GENERAL_SETTINGS_KEY)->value('value') ?? [];
        $defaults = config('avantpos.app.defaults');

        return [
            'date_format' => $stored['date_format'] ?? $defaults['date_format'],
            'time_format' => $stored['time_format'] ?? $defaults['time_format'],
        ];
    }

    public function replaceLogo(Tenant $tenant, UploadedFile $file): Tenant
    {
        $previous = $tenant->logo_path;
        $path = $this->media->storeImage($file, 'logo');

        $tenant->forceFill(['logo_path' => $path])->save();
        $this->audit->log('business.logo_updated', $tenant);

        // Remove the old file only after the new one is saved.
        $this->media->delete($previous);

        return $tenant;
    }

    public function removeLogo(Tenant $tenant): Tenant
    {
        if ($tenant->logo_path) {
            $this->media->delete($tenant->logo_path);
            $tenant->forceFill(['logo_path' => null])->save();
            $this->audit->log('business.logo_removed', $tenant);
        }

        return $tenant;
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function saveGeneralSettings(array $settings): void
    {
        $current = $this->generalSettings();
        $next = array_merge($current, $settings);

        if ($next === $current && TenantSetting::query()->where('key', self::GENERAL_SETTINGS_KEY)->exists()) {
            return;
        }

        TenantSetting::query()->updateOrCreate(['key' => self::GENERAL_SETTINGS_KEY], ['value' => $next]);

        if ($next !== $current) {
            $this->audit->log('business.settings_updated', old: $current, new: $next);
        }
    }

    /**
     * VAT-registered businesses default to VAT; non-VAT to percentage tax.
     */
    private function switchDefaultTaxRate(bool $vatRegistered): void
    {
        $type = $vatRegistered ? 'vat' : 'percentage_tax';
        $target = TaxRate::query()->where('type', $type)->where('is_active', true)->orderBy('id')->first();

        if (! $target) {
            return;
        }

        TaxRate::query()->whereKeyNot($target->id)->where('is_default', true)->update(['is_default' => false]);
        $target->forceFill(['is_default' => true])->save();

        $this->audit->log('tax_rate.default_changed', $target, meta: ['reason' => 'vat_registration_changed']);
    }
}
