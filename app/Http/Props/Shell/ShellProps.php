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
         * The word in the browser tab for platform admin, and null everywhere else.
         *
         * An operator works with many tabs open, and half the platform pages share a name
         * with a page beside them in the same console: "Usage" is this install's traffic
         * under Platform and the platform environment's own under Monitoring. The platform
         * section used to have its own shell, and that shell put the word in the title;
         * folding it into the one console dropped it, and the tab strip stopped
         * distinguishing the whole install from one environment on it.
         */
        public ?string $section,
        /**
         * Where the person is and where else they can go — the topbar's
         * `Workspace ▾ / Project ▾ / Environment ▾`. See {@see ShellContextProps}.
         */
        public ShellContextProps $context,
        public bool $isOperator,
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
        /**
         * The account menu's way into platform admin — operators only, and only from a
         * console it is not part of: the workspace console on the root, a tenant's
         * environment console. Where platform admin is an area of the rail, null.
         *
         * There used to be a way OUT as well, beside an amber strip across platform admin,
         * when it was a mode of its own. It is an area of the console now, and leaving it
         * is clicking another area.
         */
        public ?string $platformHref = null,
        public ?ShellNoticeProps $notice = null,
        /** What a save on this page changes — said above every page. */
        public ?ShellActsOnProps $actsOn = null,
        /**
         * The account menu's way into the platform root's own environment console — the
         * environment every workspace owner and operator signs in to. Operators only, and
         * not on that console itself.
         */
        public ?string $platformEnvironmentHref = null,
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
            'brandHref' => $this->brandHref,
            'navPinned' => $this->navPinned,
            'accountHref' => $this->accountHref,
            'switchUserHref' => $this->switchUserHref,
            'altitude' => $this->altitude->value,
            'workspaceSettingsHref' => $this->workspaceSettingsHref,
            'platformHref' => $this->platformHref,
            'notice' => $this->notice,
            'actsOn' => $this->actsOn,
            'platformEnvironmentHref' => $this->platformEnvironmentHref,
        ];
    }
}
