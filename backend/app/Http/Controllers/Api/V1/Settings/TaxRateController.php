<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TaxRateRequest;
use App\Http\Resources\TaxRateResource;
use App\Models\TaxRate;
use App\Services\Settings\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxRateController extends Controller
{
    public function __construct(private readonly ConfigurationService $config) {}

    public function index(): AnonymousResourceCollection
    {
        return TaxRateResource::collection(TaxRate::query()->orderByDesc('is_default')->orderBy('name')->get());
    }

    public function store(TaxRateRequest $request): JsonResponse
    {
        return (new TaxRateResource($this->config->createTaxRate($request->validated())))->response()->setStatusCode(201);
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate): TaxRateResource
    {
        return new TaxRateResource($this->config->updateTaxRate($taxRate, $request->validated()));
    }

    public function makeDefault(TaxRate $taxRate): TaxRateResource
    {
        return new TaxRateResource($this->config->makeDefaultTaxRate($taxRate));
    }
}
