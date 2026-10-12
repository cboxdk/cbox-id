<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\EnforceRootHostConsole;
use App\Platform\PlaneResolver;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Support\Facades\Route;

/**
 * WHAT THE ORGANIZATION CONSOLE IS ON THE PLATFORM ROOT'S HOST — a workspace's console, for
 * everybody who signs in there, operators included — and where everything else went.
 *
 * THE BUG IT ENDS. On `cboxid.com` the organization console offered every page an
 * organization in any environment has: Social login, Sign-in methods, Branding, Apps,
 * Webhooks. Each one configured the ORGANIZATION the person was acting in — their own
 * workspace's record in the root environment — which nobody signs in to with it. An
 * operator who opened `cboxid.com/social-sign-in` to put Google on the platform's sign-in
 * page got a page that looked exactly like that and silently configured something else.
 * {@see WorkspaceAltitude} hid most of them from a workspace owner's rail and still served
 * them by URL, and an operator was given all of them.
 *
 * NOW EVERY PAGE ON THIS HOST ACTS ON WHAT IT SAYS:
 *
 *  - the workspace's own pages ({@see WorkspaceAltitude}: projects, team, keys, billing,
 *    settings, the team's own sign-in to Cbox, its activity log), the person's account and
 *    the landing pages are served as they were — except that an OPERATOR's every sign-in
 *    page is the platform environment's, the team's own included;
 *  - platform admin is not on this console's rail at all: it is the Platform area of the
 *    platform environment's console (`/admin/platform/…`), and the account menu's
 *    "Platform admin" is the way there. Its pages carry their own operator gate and never
 *    this console's door, so only the rail has to leave them out;
 *  - a page that sets something an ENVIRONMENT owns — social login, sign-in methods,
 *    branding, apps, webhooks, roles… — is not served as the workspace's. An operator is
 *    sent to the same page of the platform root's own environment console
 *    (`/social-sign-in` → `/admin/social-sign-in`), which is what the platform's sign-in
 *    is; anybody else is told, on that very URL, that it is set in each environment's
 *    console, with a link to the same page in each environment they may open;
 *  - a WRITE to such a page is refused (404), so no request on this host can quietly
 *    configure a workspace's organization as though it were an environment.
 *
 * ONE LIST, READ BY THE RAIL AND THE DOOR, as {@see CustomerConsole} does it:
 * {@see ShellPayload} leaves a page off the rail by {@see keepsPage()}, and
 * {@see EnforceRootHostConsole} answers for its routes by {@see keepsRoute()}.
 *
 * NOT ON A SINGLE-TENANT INSTALL — there the root environment IS the product and this
 * console is its whole administration — and not on any other host.
 */
final readonly class RootHostConsole
{
    /** The landing and the person's own approvals, which every console needs. */
    private const OVERVIEW = ['dashboard', 'approvals'];

    /** The sign-in area — the workspace team's own sign-in, at a workspace's altitude. */
    private const SIGN_IN_AREA = 'authentication';

    public function __construct(
        private ConsoleScope $scope,
        private PlaneResolver $planes,
    ) {}

    /** Whether this request is the organization console on the platform root's host. */
    public function applies(): bool
    {
        return $this->scope->plane() === ConsolePlane::Organization && $this->planes->onAccountPlane();
    }

    /**
     * Whether a page of an area is served on this console.
     *
     * For an OPERATOR not even the workspace team's own sign-in: on this host every sign-in
     * page an operator opens is the platform environment's, because that is what an operator
     * means by "sign-in on cboxid.com" — and their own workspace's team SSO is one
     * organization of that environment, at `/admin/organizations/{organization}/sso`.
     */
    public static function keepsPage(string $area, string $route, bool $operator = false): bool
    {
        if ($operator && $area === self::SIGN_IN_AREA) {
            return false;
        }

        return ($area === 'overview' && in_array($route, self::OVERVIEW, true))
            || WorkspaceAltitude::keepsPage($area, $route);
    }

    /**
     * Whether the RAIL offers a page — {@see keepsPage()} less the landing pages, which are
     * reached by signing in and by the brand mark rather than by an icon of their own.
     */
    public static function railKeeps(string $area, string $route, bool $operator = false): bool
    {
        return $area !== 'overview' && self::keepsPage($area, $route, $operator);
    }

    /**
     * Whether a ROUTE — a page or a write one makes — is served here, judged by the rail
     * page it belongs to ({@see CustomerConsole::pageOf()}). A route that belongs to no page
     * (a sign-in ceremony, a step-up, ⌘K) is served.
     */
    public static function keepsRoute(string $route, bool $operator = false): bool
    {
        $owner = CustomerConsole::pageOf($route);

        return $owner === null || self::keepsPage($owner[0], $owner[1], $operator);
    }

    /**
     * The environment console's page for the same thing — `environment.social-providers`
     * for `social-providers` — or null when the environment console has no such page.
     */
    public static function environmentTwin(string $route): ?string
    {
        $twin = 'environment.'.$route;

        return Route::has($twin) ? $twin : null;
    }

    /** The rail's label for the page a route belongs to — "Social login" — or null. */
    public static function label(string $route): ?string
    {
        $owner = CustomerConsole::pageOf($route);

        if ($owner === null) {
            return null;
        }

        foreach (Console::nav()->areas() as $area) {
            foreach ($area->pages() as $page) {
                if ($area->key === $owner[0] && $page->route === $owner[1]) {
                    return $page->label;
                }
            }
        }

        return null;
    }
}
