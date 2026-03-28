<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * 成功响应
     */
    public static function success(mixed $data = null, string $message = 'success', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ], $httpStatus);
    }

    /**
     * 创建成功
     */
    public static function created(mixed $data = null, string $message = 'created'): JsonResponse
    {
        return static::success($data, $message, 201);
    }

    /**
     * 无内容成功
     */
    public static function noContent(): JsonResponse
    {
        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => null,
        ], 200);
    }

    /**
     * 错误响应
     */
    public static function error(int $errorCode, string $message, int $httpStatus = 400, mixed $data = null): JsonResponse
    {
        return response()->json([
            'code'    => $errorCode,
            'message' => $message,
            'data'    => $data,
        ], $httpStatus);
    }
}
