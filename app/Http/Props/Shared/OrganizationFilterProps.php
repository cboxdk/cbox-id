<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Console\OrganizationFilter;

/**
 * The Organization filter chip on an environment-wide list ({@see OrganizationFilter}): which
 * organization the list is narrowed to, if any, and where to look one up.
 *
 * The chip adds and removes `?organization=` on the page's own URL, so the server does not
 * say where "cleared" goes — it is the same list without the parameter.
 */
final readonly class OrganizationFilterProps implements Prop
{
    /** What `hrefTemplate` says where the chosen organization's id goes. */
    public const PLACEHOLDER = '__organization__';

    public function __construct(
        public OrganizationFilter $filter,
        public string $lookupHref,
        /**
         * Where choosing an organization GOES, instead of narrowing this list — for a list
         * whose per-organization half is a page of the organization's own (the token vault):
         * a URL with {@see self::PLACEHOLDER} where the id belongs.
         */
        public ?string $hrefTemplate = null,
    ) {}

    /**
     * @return array{parameter: string, selected: array{id: string, name: string}|null, unknown: bool, lookupHref: string, hrefTemplate: string|null}
     */
    public function toArray(): array
    {
        return [
            'parameter' => OrganizationFilter::PARAMETER,
            'selected' => $this->filter->id === null
                ? null
                : ['id' => $this->filter->id, 'name' => (string) $this->filter->name],
            'unknown' => $this->filter->unknown,
            'lookupHref' => $this->lookupHref,
            'hrefTemplate' => $this->hrefTemplate,
        ];
    }
}
