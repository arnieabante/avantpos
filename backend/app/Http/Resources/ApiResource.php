<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource:
 *  - collections use the standard pagination envelope
 *    { "data": [...], "meta": { "pagination": {...} } }
 *  - status codes are explicit: 200 by default; controllers that create a
 *    resource call ->response()->setStatusCode(201). (Laravel would
 *    otherwise infer 201 from Model::$wasRecentlyCreated.)
 */
abstract class ApiResource extends JsonResource
{
    protected static function newCollection($resource)
    {
        return new ApiResourceCollection($resource, static::class);
    }

    public function toResponse($request): JsonResponse
    {
        return parent::toResponse($request)->setStatusCode(200);
    }
}
