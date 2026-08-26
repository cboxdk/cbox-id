<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * WHICH ORGANIZATION THE ENVIRONMENT CONSOLE IS ACTING ON, in the chrome.
 *
 * Every page in the environment console is scoped to it, so it is breadcrumb information —
 * it sits in the topbar rather than being repeated as a field on each page, which is how
 * the two consoles once came to disagree about which organization a form was writing to.
 *
 * DELIBERATELY NOT A LIST OF OPTIONS, unlike the account plane's switcher beside it. That
 * one names the handful of organizations a person belongs to; this one names every tenant
 * in the environment, which is unbounded — the blade that preceded it rendered a `<form>`
 * per organization and took one customer's console from 59 KB to 3.5 MB on every page.
 * So the chrome carries the CURRENT one and where to search, and the search is bounded
 * whatever the set does.
 */
final readonly class ActingOrganizationProps implements Prop
{
    public function __construct(
        /** Null means the whole environment, unfiltered — the ordinary state, not a gap. */
        public ?string $id,
        public ?string $name,
        public string $searchUrl,
        public string $chooseUrl,
        public string $clearUrl,
    ) {}

    /**
     * @return array{id: string|null, name: string|null, searchUrl: string, chooseUrl: string, clearUrl: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'searchUrl' => $this->searchUrl,
            'chooseUrl' => $this->chooseUrl,
            'clearUrl' => $this->clearUrl,
        ];
    }
}
