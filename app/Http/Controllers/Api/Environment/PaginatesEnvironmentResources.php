<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared cursor pagination + response envelope for the environment management API.
 * Environment resources (orgs, users) are unbounded, so lists page by an opaque
 * `after` cursor over the monotonic ULID id — stable under concurrent inserts,
 * unlike offset paging. Every response uses the same `{data, meta}` shape as the
 * account plane, so a generated client sees one contract across both.
 */
trait PaginatesEnvironmentResources
{
    /**
     * @return array{0: int, 1: string|null}
     */
    private function cursor(Request $request): array
    {
        $limit = min(100, max(1, $request->integer('limit', 50)));
        $after = $request->string('after')->toString();

        return [$limit, $after !== '' ? $after : null];
    }

    /**
     * Take one more than the limit to detect a further page without a count query.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @param  callable(TModel): array<string, mixed>  $present
     */
    private function page(Collection $rows, int $limit, callable $present): JsonResponse
    {
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit);
        $last = $visible->last();

        return response()->json([
            'data' => $visible->map($present)->values()->all(),
            'meta' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null ? $last->getKey() : null,
            ],
        ]);
    }

    private function notFound(string $resource): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => ucfirst($resource).' not found.'], 404);
    }
}
