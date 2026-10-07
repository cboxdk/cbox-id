<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Input\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A cursor-paginated list, the same way every list in the management API pages: `limit`
 * (1–100, default 50, clamped rather than refused) and `after`, the last id of the page
 * before; one row more than the limit is read so the next page is known without a count.
 */
trait Paginates
{
    /**
     * @return list<Field>
     */
    protected static function pageFields(): array
    {
        return [
            Field::integer('limit')->describe('Items per page, 1–100. Default 50.'),
            Field::string('after')->describe('The `next_cursor` of the previous page.'),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  Already narrowed; only ordered, bounded and advanced here.
     * @param  callable(TModel): array<string, mixed>  $present
     */
    protected function page(Builder $query, ActionContext $context, callable $present): ActionResult
    {
        $asked = $context->input['limit'] ?? 50;
        $limit = min(100, max(1, is_numeric($asked) ? (int) $asked : 50));
        $after = $context->nullableString('after');
        $key = $query->getModel()->getQualifiedKeyName();

        if ($after !== null) {
            $query->where($key, '>', $after);
        }

        /** @var Collection<int, TModel> $rows */
        $rows = $query->orderBy($key)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit);
        $last = $visible->last();

        $payload = [];

        foreach ($visible as $row) {
            $payload[] = $present($row);
        }

        return ActionResult::page($visible, $payload, [
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null ? $last->getKey() : null,
        ]);
    }
}
