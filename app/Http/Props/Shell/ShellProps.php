<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\ConsoleAltitude;
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
        /**
         * Where the person is and where else they can go — the topbar's
         * `Workspace ▾ / Project ▾ / Environment ▾`. See {@see ShellContextProps}.
         */
        public ShellContextProps $context,
        public bool $isOperator,
        /**
         * Whether this page is in PLATFORM ADMIN — the install as a whole, every customer
         * on it. Drawn as a mode of its own (a strip, a rail of its own, a way out) because
         * a click there can suspend a customer, and a page in the middle of the console
         * looked like every other page.
         */
        public bool $platformMode,
        public string $brandHref,
        public bool $navPinned,
        /**
         * The person's own account page and the signed-in-user switcher — ABSOLUTE on the
         * environment console, because both live on the workspace's host. As relative links
         * on a tenant host they reached a page that asked for a sign-in the environment
         * administrator does not have there, and bounced them to the tenant's end-user
         * sign-in form.
         */
        public string $accountHref,
        public string $switchUserHref,
        /** Which console this is — a workspace's, an organization's, or an environment's. */
        public ConsoleAltitude $altitude,
        /**
         * The account menu's "Workspace settings", where this person may change them —
         * absolute on the environment console, for the same reason as `$accountHref`.
         */
        public ?string $workspaceSettingsHref = null,
        /** The account menu's way INTO platform admin. Operators only. */
        public ?string $platformHref = null,
        /** The platform strip's way OUT — the operator's own console. Platform mode only. */
        public ?string $exitPlatformHref = null,
        public ?ShellNoticeProps $notice = null,
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
            'context' => $this->context,
            'isOperator' => $this->isOperator,
            'platformMode' => $this->platformMode,
            'brandHref' => $this->brandHref,
            'navPinned' => $this->navPinned,
            'accountHref' => $this->accountHref,
            'switchUserHref' => $this->switchUserHref,
            'altitude' => $this->altitude->value,
            'workspaceSettingsHref' => $this->workspaceSettingsHref,
            'platformHref' => $this->platformHref,
            'exitPlatformHref' => $this->exitPlatformHref,
            'notice' => $this->notice,
        ];
    }
}
