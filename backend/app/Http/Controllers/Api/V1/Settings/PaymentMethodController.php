<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Services\Settings\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly ConfigurationService $config) {}

    public function index(): AnonymousResourceCollection
    {
        return PaymentMethodResource::collection(PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get());
    }

    public function store(PaymentMethodRequest $request): JsonResponse
    {
        return (new PaymentMethodResource($this->config->createPaymentMethod($request->validated())))->response()->setStatusCode(201);
    }

    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        return new PaymentMethodResource($this->config->updatePaymentMethod($paymentMethod, $request->validated()));
    }
}
