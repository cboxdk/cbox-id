<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * A page of a list whose TOTAL is deliberately not known.
 *
 * `paginate()` runs a COUNT(*) over the filtered set on every render. On the activity log
 * that is a full index scan of the environment's whole audit partition — a table with no
 * retention, which only grows — paid on each keystroke of a debounced filter, to render a
 * page count nobody uses on an append-only feed.
 *
 * `simplePaginate()` answers the question that is actually asked ("is there more?") with
 * one LIMIT n+1 read, and this is the shape of that answer. It is a different prop from
 * {@see PaginationProps} rather than a nullable-total version of it, because a component
 * handed "total: null" has to decide what to draw, and this way the page it is on cannot
 * promise a position it does not know.
 */
final readonly class SimplePaginationProps implements Prop
{
    public function __construct(
        public int $currentPage,
        public int $perPage,
        /** How many rows are on THIS page — what an empty filter has to report. */
        public int $count,
        public bool $hasMore,
    ) {}

    /**
     * @template TValue
     *
     * @param  Paginator<int, TValue>  $paginator
     */
    public static function from(Paginator $paginator): self
    {
        return new self(
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            // `items()` rather than `count()`: the contract does not declare Countable,
            // and the concrete paginator is not what every caller has to hand.
            count: count($paginator->items()),
            hasMore: $paginator->hasMorePages(),
        );
    }

    /**
     * @return array{currentPage: int, perPage: int, count: int, hasMore: bool}
     */
    public function toArray(): array
    {
        return [
            'currentPage' => $this->currentPage,
            'perPage' => $this->perPage,
            'count' => $this->count,
            'hasMore' => $this->hasMore,
        ];
    }
}
