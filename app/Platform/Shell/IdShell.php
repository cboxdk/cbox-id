<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\NavPin;
use App\Platform\Console\ShellPayload;
use App\Platform\Theme;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

        $unread = $built->notifications->unread;
        $bell = app(ApprovalsNotificationSource::class)->url($this->request);
        $shell['notifications'] = $unread > 0 && $bell !== null
            ? ['enabled' => true, 'unread' => $unread, 'url' => $bell]
            : ['enabled' => false];

        // The app's own cookies, which the root view reads before the first paint.
        $shell['preferences'] = [
            ...$built->preferences->toArray(),
            'theme' => Theme::preference() ?? 'system',
            'railPinned' => NavPin::pinned($this->request),
        ];

        $shell['apps'] = [...$built->apps->toArray(), 'productsUrl' => 'https://cbox.dk'];

        // Records are searched in the console the page stands in: an environment console's
        // own search runs behind that environment's door, in that environment.
        if (ShellRequest::environmentPath($this->request) !== null && Route::has('environment.search')) {
            $palette = $built->palette->toArray();
            $query = parse_url($palette['searchUrl'], PHP_URL_QUERY);
            $shell['palette'] = [...$palette, 'searchUrl' => route('environment.search', [], false).(is_string($query) && $query !== '' ? '?'.$query : '')];
        }

        return $shell;
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
                    'label' => $page->label,
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
                'label' => $area->label,
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
