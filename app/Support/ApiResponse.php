<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * The single source of truth for the API response envelope required by
 * docs/ENGINEERING_STANDARDS.md:
 *
 *   { success, message, data, meta, errors }
 *
 * Every JSON API controller returns through here so the shape is identical
 * across the whole surface (and any future mobile client can rely on it).
 */
class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        // Unwrap paginators so pagination lands in `meta`, not inside `data`.
        if ($data instanceof AbstractPaginator) {
            $meta = array_merge(self::paginationMeta($data), $meta);
            $data = $data->items();
        }

        if ($data instanceof ResourceCollection) {
            $resource = $data->resource;
            if ($resource instanceof AbstractPaginator) {
                $meta = array_merge(self::paginationMeta($resource), $meta);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::normalize($data),
            'meta' => (object) $meta,
            'errors' => [],
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully', array $meta = []): JsonResponse
    {
        return self::success($data, $message, $meta, 201);
    }

    public static function error(
        string $message = 'Something went wrong',
        array $errors = [],
        int $status = 400,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => (object) $meta,
            'errors' => $errors,
        ], $status);
    }

    private static function normalize(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof Arrayable) {
            return $data;
        }

        return $data;
    }

    private static function paginationMeta(AbstractPaginator $paginator): array
    {
        return [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
                'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }
}
