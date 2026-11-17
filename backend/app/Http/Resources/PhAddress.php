<?php

namespace App\Http\Resources;

use App\Models\PhBarangay;
use App\Models\PhCity;
use App\Models\PhProvince;
use App\Models\PhRegion;
use Illuminate\Database\Eloquent\Model;

/**
 * Address block shared by business and branch payloads: PSGC codes plus
 * their names (for display) and a one-line formatted address.
 */
class PhAddress
{
    /**
     * @return array<string, string|null>
     */
    public static function from(Model $model): array
    {
        $region = $model->region_code ? PhRegion::query()->find($model->region_code) : null;
        $province = $model->province_code ? PhProvince::query()->find($model->province_code) : null;
        $city = $model->city_code ? PhCity::query()->find($model->city_code) : null;
        $barangay = $model->barangay_code ? PhBarangay::query()->find($model->barangay_code) : null;

        $formatted = implode(', ', array_filter([
            $model->address_line,
            $barangay?->name ? 'Brgy. '.$barangay->name : null,
            $city?->name,
            $province?->name,
            $model->postal_code,
        ]));

        return [
            'address_line' => $model->address_line,
            'region_code' => $model->region_code,
            'region_name' => $region?->name,
            'province_code' => $model->province_code,
            'province_name' => $province?->name,
            'city_code' => $model->city_code,
            'city_name' => $city?->name,
            'barangay_code' => $model->barangay_code,
            'barangay_name' => $barangay?->name,
            'postal_code' => $model->postal_code,
            'formatted' => $formatted !== '' ? $formatted : null,
        ];
    }
}
