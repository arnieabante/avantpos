<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ReceiptTemplateRequest;
use App\Http\Resources\ReceiptTemplateResource;
use App\Models\ReceiptTemplate;
use App\Services\Settings\ConfigurationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceiptTemplateController extends Controller
{
    public function __construct(private readonly ConfigurationService $config) {}

    public function index(): AnonymousResourceCollection
    {
        return ReceiptTemplateResource::collection(ReceiptTemplate::query()->orderByDesc('is_default')->orderBy('name')->get());
    }

    public function store(ReceiptTemplateRequest $request): JsonResponse
    {
        return (new ReceiptTemplateResource($this->config->createReceiptTemplate($request->validated())))->response()->setStatusCode(201);
    }

    public function update(ReceiptTemplateRequest $request, ReceiptTemplate $receiptTemplate): ReceiptTemplateResource
    {
        return new ReceiptTemplateResource($this->config->updateReceiptTemplate($receiptTemplate, $request->validated()));
    }

    public function makeDefault(ReceiptTemplate $receiptTemplate): ReceiptTemplateResource
    {
        return new ReceiptTemplateResource($this->config->makeDefaultReceiptTemplate($receiptTemplate));
    }

    public function destroy(ReceiptTemplate $receiptTemplate): JsonResponse
    {
        $this->config->deleteReceiptTemplate($receiptTemplate);

        return ApiResponse::noContent();
    }
}
