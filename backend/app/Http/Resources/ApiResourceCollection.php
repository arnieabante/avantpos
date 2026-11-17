<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiResourceCollection extends AnonymousResourceCollection
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'pagination' => array_filter([
                    'current_page' => $paginated['current_page'] ?? null,
                    'per_page' => $paginated['per_page'] ?? null,
                    'total' => $paginated['total'] ?? null,
                    'last_page' => $paginated['last_page'] ?? null,
                    'next_cursor' => $paginated['next_cursor'] ?? null,
                    'prev_cursor' => $paginated['prev_cursor'] ?? null,
                ], fn ($v) => $v !== null),
            ],
        ];
    }
}
