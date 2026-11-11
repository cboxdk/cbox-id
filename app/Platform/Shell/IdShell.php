<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\NavPin;
use App\Platform\Console\ShellPayload;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\Theme;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * THE `shell` PROP: the one contract every Cbox app renders with cbox-ui's `CboxShell`.
 *
 * console-kit builds it ({@see ShellServiceProvider}): the context levels, the palette,
 * Help, the bell, the banner, the account menu and the preferences. Two parts are ID's own:
 *
 *  - THE RAIL. Which pages a person may open differs by plane, role, entitlement, feature
 *    and host, and {@see ShellPayload} already decides it for both consoles, with the
 *    tests behind every rule. Its areas are sent in the kit's shape rather than decided a
 *    second time.
 *  - THE BELL leads to the environment's Approvals page and shows only while something is
 *    waiting there.
 *
 * Null where a page has no chrome (sign-in, an error) and on a customer environment's own
 * host: that console is white-labelled, with no Cbox app switcher and no Cbox Help, which
 * `CboxShell` cannot leave out yet, so it keeps its own chrome ({@see tenant()}).
 */
final readonly class IdShell
{
    /** The account menu's regional format, written by the page (`resources/js/lib/formats.ts`). */
    public const FORMAT_COOKIE = 'cbox-format';

    /** The account menu's time zone, written by the page. */
    public const TIME_ZONE_COOKIE = 'cbox-time-zone';

    public function __construct(private Request $request) {}

    /** @return array<string, mixed>|null */
    public function build(): ?array
    {
        $legacy = app(ShellPayload::class)->build();

        if (! $legacy instanceof ShellProps || ShellRequest::onTenantConsole()) {
            return null;
        }

        $built = Console::shell()->build($this->request);
        $shell = $built->toArray();
        $shell['nav'] = self::nav($legacy);
        $shell['section'] = $legacy->section;

        $unread = $built->notifications->unread;
        $bell = app(ApprovalsNotificationSource::class)->url($this->request);
        $shell['notifications'] = $unread > 0 && $bell !== null
            ? ['enabled' => true, 'unread' => $unread, 'url' => $bell]
            : ['enabled' => false];

        // The app's own cookies, which the root view reads before the first paint.
        $shell['preferences'] = array_filter([
            ...$built->preferences->toArray(),
            'theme' => Theme::preference() ?? 'system',
            'railPinned' => NavPin::pinned($this->request),
            // The account menu's regional format and time zone (`resources/js/lib/formats.ts`).
            'regionalFormat' => self::regionalFormat($this->request->cookie(self::FORMAT_COOKIE)),
            'timeZone' => self::timeZone($this->request->cookie(self::TIME_ZONE_COOKIE)),
        ], static fn (mixed $value): bool => $value !== null);

        $shell['apps'] = [...$built->apps->toArray(), 'productsUrl' => 'https://cbox.dk'];

        // Records are searched in the console the page stands in, behind that console's own
        // door: an environment console's in that environment, an organization page's in the
        // organization its address names (`orgs.bind`, never the session's). Anywhere else
        // the kit's `/shell/search` answers "Go to" and no records ({@see IdSearchProvider}).
        $search = match (true) {
            ShellRequest::environmentPath($this->request) !== null && Route::has('environment.search') => route('environment.search', [], false),
            is_string(URL::getDefaultParameters()['org'] ?? null) && Route::has('orgs.search') => route('orgs.search', [], false),
            default => null,
        };

        if ($search !== null) {
            $palette = $built->palette->toArray();
            $query = parse_url($palette['searchUrl'], PHP_URL_QUERY);
            $shell['palette'] = [...$palette, 'searchUrl' => $search.(is_string($query) && $query !== '' ? '?'.$query : '')];
        }

        return $shell;
    }

    /**
     * A BCP 47 tag such as `da-DK`, or null. The cookie is written by the page, so it is
     * matched, not trusted: it goes into the props and from there into `Intl`.
     */
    public static function regionalFormat(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}\z/', $value) === 1 ? $value : null;
    }

    /** An IANA time zone this PHP knows (`Europe/Copenhagen`), or null. */
    public static function timeZone(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 64 && in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }

    /** The white-labelled customer console's own chrome, on a customer environment's host. */
    public function tenant(): ?ShellProps
    {
        return ShellRequest::onTenantConsole() ? app(ShellPayload::class)->build() : null;
    }

    /** @return array{areas: list<array<string, mixed>>, activeArea: string, activePage?: string} */
    private static function nav(ShellProps $legacy): array
    {
        $activePage = null;
        $areas = array_map(static function (NavAreaProps $area) use (&$activePage): array {
            $pages = array_map(static function (NavPageProps $page) use (&$activePage, $area): array {
                if ($page->active && $area->active) {
                    $activePage = $page->route;
                }

                return array_filter([
                    'key' => $page->route,
                    // In the person's language, as the props' own toArray() would send it.
                    'label' => ConsoleLabels::navigation($page->label),
                    'url' => $page->href,
                    'href' => $page->href,
                    // "Enterprise": the page is shown and marked, so a plan that does not
                    // have it can still find it, and leads to the upgrade prompt.
                    'locked' => $page->badge !== null ? true : null,
                    'count' => $page->count !== null && $page->count > 0 ? $page->count : null,
                    'keywords' => $page->keywords === [] ? null : $page->keywords,
                ], static fn (mixed $value): bool => $value !== null);
            }, $area->pages);

            return [
                'key' => $area->key,
                'label' => ConsoleLabels::navigation($area->label),
                'url' => $area->href,
                'href' => $area->href,
                'icon' => $area->icon,
                'pages' => $pages,
            ];
        }, $legacy->areas);

        return array_filter([
            'areas' => $areas,
            'activeArea' => $legacy->activeArea ?? '',
            'activePage' => $activePage,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
