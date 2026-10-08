<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\BindConsoleOrganization;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * WHICH ORGANIZATION AN ENVIRONMENT-WIDE LIST IS NARROWED TO — `?organization=` in its URL,
 * or nothing.
 *
 * The replacement for half of what the "acting organization" did. That was a choice kept in
 * the session that every list read silently, so a link to a list showed whoever opened it
 * whatever THEY had picked last. A filter is part of the address instead: the list says
 * what it is showing, the chip on it says so out loud, and the link means the same thing to
 * everybody.
 *
 * THREE STATES, and the third is the reason this is a class rather than a nullable id:
 *
 *  - NONE: the whole environment, every organization's rows — the ordinary state.
 *  - ONE ORGANIZATION of this environment: its rows.
 *  - UNKNOWN: an id that names no organization here — another environment's, a deleted
 *    one, a typo. That is an EMPTY list, never an unfiltered one. A filter that fell back
 *    to "everything" on an id it did not recognise would turn a guessed id into a way of
 *    seeing that nothing was filtered, and would show a person a list they believed was
 *    one customer's while it was every customer's.
 *
 * The other half — the organization a page ACTS on — is a route parameter instead
 * ({@see BindConsoleOrganization}), and is never read from a query.
 */
final readonly class OrganizationFilter
{
    /** The query parameter a list is filtered by. */
    public const PARAMETER = 'organization';

    private function __construct(
        /** The organization the list is narrowed to; null for none, and for an unknown one. */
        public ?string $id,
        public ?string $name,
        /** An id was asked for and names no organization here: the list is empty. */
        public bool $unknown,
    ) {}

    public static function none(): self
    {
        return new self(null, null, false);
    }

    public static function of(string $id, string $name): self
    {
        return new self($id, $name, false);
    }

    public static function unknown(): self
    {
        return new self(null, null, true);
    }

    /**
     * The filter a request asks for, checked against THIS environment — the model's
     * environment scope is what makes another environment's id resolve to nothing.
     */
    public static function fromRequest(Request $request): self
    {
        $asked = $request->query(self::PARAMETER);

        if ($asked === null || $asked === '') {
            return self::none();
        }

        if (! is_string($asked) || strlen($asked) > 64) {
            return self::unknown();
        }

        $name = Organization::query()->whereKey($asked)->value('name');

        return is_string($name) ? self::of($asked, $name) : self::unknown();
    }

    /** Whether the list is narrowed at all — to one organization, or to nothing. */
    public function active(): bool
    {
        return $this->id !== null || $this->unknown;
    }

    /**
     * Narrow a query to the filtered organization's rows: unchanged with no filter, and
     * matching NOTHING for an unknown one.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $column = 'organization_id'): Builder
    {
        if ($this->unknown) {
            return $query->whereRaw('1 = 0');
        }

        return $this->id === null ? $query : $query->where($column, $this->id);
    }
}
