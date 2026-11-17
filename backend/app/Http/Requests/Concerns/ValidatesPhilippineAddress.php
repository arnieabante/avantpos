<?php

namespace App\Http\Requests\Concerns;

use App\Models\PhBarangay;
use App\Models\PhCity;
use App\Models\PhProvince;
use Illuminate\Validation\Validator;

/**
 * PSGC address fields (region > province > city/municipality > barangay).
 * Each level must belong to the level above it. NCR cities have no province.
 *
 * Use addressRules() in rules() and addressHierarchyCheck() in after().
 */
trait ValidatesPhilippineAddress
{
    /**
     * @return array<string, mixed>
     */
    protected function addressRules(): array
    {
        return [
            'address_line' => ['nullable', 'string', 'max:255'],
            'region_code' => ['nullable', 'string', 'max:10', 'exists:ph_regions,code'],
            'province_code' => ['nullable', 'string', 'max:10', 'exists:ph_provinces,code'],
            'city_code' => ['nullable', 'string', 'max:10', 'exists:ph_cities,code'],
            'barangay_code' => ['nullable', 'string', 'max:10', 'exists:ph_barangays,code'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    protected function addressHierarchyCheck(): callable
    {
        return function (Validator $validator): void {
            if ($validator->errors()->hasAny(['region_code', 'province_code', 'city_code', 'barangay_code'])) {
                return;
            }

            $region = $this->input('region_code');
            $province = $this->input('province_code');
            $city = $this->input('city_code');
            $barangay = $this->input('barangay_code');

            if ($province && PhProvince::query()->whereKey($province)->value('region_code') !== $region) {
                $validator->errors()->add('province_code', 'The province does not belong to the selected region.');
            }

            if ($city) {
                $row = PhCity::query()->find($city);
                if ($row && ($row->region_code !== $region || $row->province_code !== ($province ?: null))) {
                    $validator->errors()->add('city_code', 'The city/municipality does not belong to the selected province.');
                }
            }

            if ($barangay && PhBarangay::query()->whereKey($barangay)->value('city_code') !== $city) {
                $validator->errors()->add('barangay_code', 'The barangay does not belong to the selected city/municipality.');
            }
        };
    }
}
