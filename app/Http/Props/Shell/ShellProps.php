<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\ShellPayload;

/**
 * THE CHROME AROUND EVERY CONSOLE PAGE, as one object.
 *
 * Under Volt this was two blade layouts, each recomputing the same answers from the same
 * sources in its own idiom — 385 lines in one, 193 in the other, and the drift between
 * them is documented all over both files. It is one shape now, built once per request by
 * {@see ShellPayload}, and the two planes differ only in what goes
 * into it.
 *
 * It is a SHARED prop rather than something each page renders, because the chrome is not
 * a page's business: a page that had to remember to draw the impersonation banner is a
 * page that can forget to.
 */
final readonly class ShellProps implements Prop
{
    /**
     * @param  list<NavAreaProps>  $areas
     * @param  list<SwitchOptionProps>  $organizations  empty unless there is more than one to choose between
     * @param  list<SwitchOptionProps>  $environments  operators only
     */
    public function __construct(
        public array $areas,
        public ?string $activeArea,
        /**
         * The word in the browser tab for the platform section, and null everywhere else.
         *
         * An operator works with many tabs open, and half the platform pages share a name
         * with a page about the operator's OWN organization: "Usage" is this install's
         * traffic in one and one customer's bill in the other. The platform section used
         * to have its own shell, and that shell put the word in the title; folding it into
         * the one console dropped it, and the tab strip stopped distinguishing the whole
         * install from one customer on it.
         */
        public ?string $section,
        public array $organizations,
        /**
         * The environment plane's acting organization, and null on every other plane.
         *
         * Separate from `$organizations` because the two answer different questions: that
         * one is "which of MY organizations", answered with a list because a person belongs
         * to a handful; this is "which TENANT of this environment", where the set is
         * unbounded and the chrome must never try to enumerate it.
         */
        public ?ActingOrganizationProps $actingOrganization,
        public array $environments,
        public bool $isOperator,
        public string $brandHref,
        public bool $navPinned,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'areas' => $this->areas,
            'activeArea' => $this->activeArea,
            'section' => $this->section,
            'organizations' => $this->organizations,
            'actingOrganization' => $this->actingOrganization,
            'environments' => $this->environments,
            'isOperator' => $this->isOperator,
            'brandHref' => $this->brandHref,
            'navPinned' => $this->navPinned,
        ];
    }
}
