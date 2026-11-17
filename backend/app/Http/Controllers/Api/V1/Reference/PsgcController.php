<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Models\PhBarangay;
use App\Models\PhCity;
use App\Models\PhProvince;
use App\Models\PhRegion;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Philippine Standard Geographic Code lookups for address pickers.
 * Global reference data (not tenant-specific), cached for a day.
 */
class PsgcController extends Controller
{
    private const TTL = 86_400;

    public function regions(): JsonResponse
    {
        return ApiResponse::data(Cache::remember('psgc:regions', self::TTL, fn () => PhRegion::query()
            ->orderBy('code')->get(['code', 'name', 'region_name'])->toArray()));
    }

    public function provinces(Request $request): JsonResponse
    {
        $region = $request->validate(['region_code' => ['required', 'string', 'max:10']])['region_code'];

        return ApiResponse::data(Cache::remember("psgc:provinces:{$region}", self::TTL, fn () => PhProvince::query()
            ->where('region_code', $region)->orderBy('name')->get(['code', 'name'])->toArray()));
    }

    /**
     * Cities/municipalities of a province, or — with only region_code — the
     * cities of that region that have no province (NCR, independent cities).
     */
    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region_code' => ['required_without:province_code', 'nullable', 'string', 'max:10'],
            'province_code' => ['nullable', 'string', 'max:10'],
        ]);

        $province = $data['province_code'] ?? null;
        $region = $data['region_code'] ?? null;
        $key = $province ? "psgc:cities:p:{$province}" : "psgc:cities:r:{$region}";

        return ApiResponse::data(Cache::remember($key, self::TTL, fn () => PhCity::query()
            ->when($province, fn ($q) => $q->where('province_code', $province))
            ->when(! $province, fn ($q) => $q->where('region_code', $region)->whereNull('province_code'))
            ->orderBy('name')->get(['code', 'name', 'type'])->toArray()));
    }

    public function barangays(Request $request): JsonResponse
    {
        $city = $request->validate(['city_code' => ['required', 'string', 'max:10']])['city_code'];

        return ApiResponse::data(Cache::remember("psgc:barangays:{$city}", self::TTL, fn () => PhBarangay::query()
            ->where('city_code', $city)->orderBy('name')->get(['code', 'name'])->toArray()));
    }
}
