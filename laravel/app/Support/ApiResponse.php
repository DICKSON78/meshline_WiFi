<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Small helper that mirrors the JSON envelopes used by the legacy Express API.
 */
class ApiResponse
{
    public static function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function success(array $extra = [], string $message = 'Success', int $status = 200): JsonResponse
    {
        return self::json(array_merge(['success' => true, 'message' => $message], $extra), $status);
    }

    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return self::json(array_merge(['success' => true], $data), $status);
    }

    public static function fail(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return self::json(array_merge(['success' => false, 'message' => $message, 'error' => $message], $extra), $status);
    }

    public static function error(string $message = 'Server error. Please try again.', int $status = 500): JsonResponse
    {
        return self::json(['success' => false, 'message' => $message], $status);
    }

    public static function unauthorized(string $message = 'Authentication required'): JsonResponse
    {
        return self::json(['success' => false, 'message' => $message], 401);
    }

    public static function forbidden(string $message = 'Insufficient permissions'): JsonResponse
    {
        return self::json(['success' => false, 'message' => $message], 403);
    }
}
