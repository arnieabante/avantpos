<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Consistent JSON envelopes for responses that are not API Resources.
 *
 *   success:  { "data": ..., "meta"?: {...} }
 *   message:  { "message": "..." }
 *   error:    { "message": "...", "code": "...", "errors"?: {...} }
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function data(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, string $code, int $status, array $errors = [], array $context = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        if ($context !== []) {
            $body['context'] = $context;
        }

        return response()->json($body, $status);
    }
}
