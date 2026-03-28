<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'success', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ], $httpStatus);
    }

    protected function fail(int $code, string $message, int $httpStatus = 400, ?array $data = null): JsonResponse
    {
        return response()->json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], $httpStatus);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $message = 'success'): JsonResponse
    {
        return response()->json([
            'code'    => 0,
            'message' => $message,
            'data'    => [
                'items'      => $paginator->items(),
                'pagination' => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ],
        ]);
    }
}
