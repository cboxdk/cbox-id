<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A page of a longer list, and enough about the rest of it to move around.
 *
 * DELIBERATELY NOT Laravel's paginator array. That shape carries a rendered `links`
 * array — a list of pre-built page links with `active` and `label` keys, some of which
 * are the string "&laquo; Previous" — which exists so a blade template can loop over it
 * without thinking. Handing that to React would mean the SERVER deciding how many page
 * numbers to draw and where the ellipsis goes, which is a layout question, and it would
 * put HTML entities in props.
 *
 * What crosses is the state: which page, how many, how many rows. The component decides
 * what that looks like at 375px and at 1440px.
 */
final readonly class PaginationProps implements Prop
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public int $from,
        public int $to,
    ) {}

    /**
     * The concrete paginator, not the contract: the contract's value type is invariant,
     * so a `LengthAwarePaginator<int, WebhookEndpoint>` is not one of `<int, mixed>` and
     * every call site would have to widen its own type to pass one.
     *
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $paginator
     */
    public static function from(LengthAwarePaginator $paginator): self
    {
        return new self(
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            // Null on an empty page; zero says "no rows" without the client having to
            // special-case a missing key.
            from: $paginator->firstItem() ?? 0,
            to: $paginator->lastItem() ?? 0,
        );
    }

    /**
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: int, to: int}
     */
    public function toArray(): array
    {
        return [
            'currentPage' => $this->currentPage,
            'lastPage' => $this->lastPage,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
