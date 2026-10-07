<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Paginates;

/**
 * Page numbers, the way the workspace plane's lists have always paged: `limit` (1–100,
 * default 50, clamped rather than refused) and `page` (from 1), answered with `has_more`
 * and — exactly when there is more — `next_page`.
 *
 * Not the environment plane's cursor ({@see Paginates}), on purpose: a workspace's
 * environments and team are plan-bounded, and these lists already had callers looping on
 * `next_page` before they became actions. Changing the idiom under them would be a
 * breaking change dressed up as consistency.
 */
trait PagesByNumber
{
    /**
     * @return list<Field>
     */
    protected static function pageFields(): array
    {
        return [
            Field::integer('limit')->describe('Items per page, 1–100. Default 50.'),
            Field::integer('page')->describe('The page to read, from 1. A list says `next_page` when there is one.'),
        ];
    }

    /** @return array{0: int, 1: int} The limit and the page, both clamped. */
    protected function pageOf(ActionContext $context): array
    {
        $limit = $context->input['limit'] ?? 50;
        $page = $context->input['page'] ?? 1;

        return [
            min(100, max(1, is_numeric($limit) ? (int) $limit : 50)),
            max(1, is_numeric($page) ? (int) $page : 1),
        ];
    }

    /**
     * The `meta` of a page: `next_page` present exactly when `has_more` is true, so a
     * client can loop without computing anything.
     *
     * @return array<string, mixed>
     */
    protected function pageMeta(int $limit, int $page, bool $hasMore, ?int $total = null): array
    {
        return array_filter([
            'limit' => $limit,
            'page' => $page,
            'total' => $total,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
