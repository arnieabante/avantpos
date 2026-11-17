<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DiscountPresetRequest;
use App\Http\Resources\DiscountPresetResource;
use App\Models\DiscountPreset;
use App\Services\Settings\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DiscountPresetController extends Controller
{
    public function __construct(private readonly ConfigurationService $config) {}

    public function index(): AnonymousResourceCollection
    {
        return DiscountPresetResource::collection(
            DiscountPreset::query()->orderByRaw('statutory IS NULL')->orderBy('name')->get()
        );
    }

    public function store(DiscountPresetRequest $request): JsonResponse
    {
        return (new DiscountPresetResource($this->config->createDiscount($request->validated())))->response()->setStatusCode(201);
    }

    public function update(DiscountPresetRequest $request, DiscountPreset $discountPreset): DiscountPresetResource
    {
        return new DiscountPresetResource($this->config->updateDiscount($discountPreset, $request->validated()));
    }
}
