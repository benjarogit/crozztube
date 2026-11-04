<?php

namespace App\Support\Api\V1;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class PaginationPayload
{
    public static function lengthAware(LengthAwarePaginator $paginator, array $items): JsonResponse
    {
        return response()->json([
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }

    public static function cursor(CursorPaginator $paginator, array $items): JsonResponse
    {
        return response()->json([
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'previous_cursor' => $paginator->previousCursor()?->encode(),
                    'per_page' => $paginator->perPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }
}
