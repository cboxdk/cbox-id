<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\EnvironmentApiContext;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * Refusals use the one error shape the whole REST API promises (see ApiErrorRenderer):
 * `{error, message}`, where `error` is a stable code a client switches on.
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
     * `$query` narrowed to one page in id order, starting after the request's cursor — the
     * query is taken as built (filters, the organization bound in its WHERE clause) and
     * only ordered, bounded and advanced here. One row more than the limit, so {@see page()}
     * can tell whether another page follows without a count query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function pageOf(Builder $query, int $limit, ?string $after): Builder
    {
        $key = $query->getModel()->getQualifiedKeyName();

        if ($after !== null) {
            $query->where($key, '>', $after);
        }

        return $query->orderBy($key)->limit($limit + 1);
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

    /**
     * The organization with this id IN THIS ENVIRONMENT — the host-resolved environment
     * scope is on the query, so another environment's id resolves to nothing, never to a
     * row that is compared afterwards.
     */
    private function organization(string $id): ?Organization
    {
        return Organization::query()->whereKey($id)->first();
    }

    /**
     * The environment API key this request authenticated with. {@see AuthenticateEnvironmentApi}
     * sets it before any of these controllers runs, so a missing one is a routing mistake
     * and is refused as unauthenticated rather than acted on anonymously.
     */
    private function actingKey(): EnvironmentApiKey
    {
        return app(EnvironmentApiContext::class)->key() ?? abort(401);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function item(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    private function notFound(string $resource): JsonResponse
    {
        return $this->refuse('not_found', ucfirst($resource).' not found.', 404);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function refuse(string $error, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status, $headers);
    }
}
