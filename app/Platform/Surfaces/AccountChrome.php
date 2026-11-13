<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\NavPin;
use App\Platform\Console\ShellPayload;
use App\Platform\CurrentUser;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\OAuth\AppSignIns;
use App\Platform\Shell\IdShell;
use App\Platform\Theme;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Http\Request;

/**
 * THE CHROME OF A PERSON'S OWN PAGES ON THE LOGIN HOST.
 *
 * The same `CboxShell` every Cbox app has ({@see shell()}): the rail with the person's own
 * pages and the account menu at its foot, Switch app in the header, ⌘K. What the console's
 * shell carries beyond that is left out, because the login host is not the console: no
 * organization or environment switcher, no records search, no "+", no bell. Each of those
 * acts in the console, and on this host its address would be a 404.
 *
 * Beside it, {@see toProps()}: where the person came from ("Back to …"), drawn over the
 * page. Null everywhere else — the console and a tenant's host keep the chrome they
 * have, the tenant's host with its own "Back to …" ({@see tenantBack()}) — and null for a
 * guest, whose page is a door.
 *
 *  - BACK leads to the address the console or an app sent along (`?return_to=`), else to
 *    the page that linked here, both validated by {@see ReturnUrl}, else to the console.
 *  - THE PAGES are the console rail's own "account" area, decided by the same code
 *    ({@see ShellPayload}): a module's personal page, a feature that is switched off and a
 *    page this deployment does not offer are answered once, there.
 */
final readonly class AccountChrome
{
    /** The console-kit area that holds a person's own pages. */
    private const string AREA = 'account';

    /** Each of the person's pages by its route, as the rail draws it (cbox-ui's icon set). */
    private const array PAGE_ICONS = [
        'account.apps' => 'layout-grid',
        'account' => 'shield',
        'account.activity' => 'activity',
        'devices.mine' => 'shield-check',
        'account.api-keys' => 'key',
        'account.pipes' => 'plug',
        'account.services' => 'boxes',
        'account.preferences' => 'settings',
    ];

    public function __construct(
        private Surfaces $surfaces,
        private ReturnUrl $returns,
        private CurrentUser $user,
    ) {}

    /** Whether this request draws a person's pages without the console around them. */
    public function applies(?Request $request = null): bool
    {
        return $this->surfaces->current($request) === Surface::Login && $this->user->check();
    }

    /**
     * @return array{back: array{url: string, label: string}, pages: list<array{route: string, label: string, href: string, active: bool}>, signOutHref: string, switchAccountHref: string}|null
     */
    public function toProps(Request $request): ?array
    {
        // Only where the layout draws it: the doors and the consent screen have their own.
        if (! $this->applies($request) || $this->surfaces->kindOf($request) !== PathKind::Account) {
            return null;
        }

        return [
            'back' => $this->origin($request) ?? ['url' => app(ConsoleEntry::class)->url(), 'label' => $this->returns->ownLabel()],
            'pages' => $this->pages(),
            'signOutHref' => route('logout', [], false),
            'switchAccountHref' => route('accounts', [], false),
        ];
    }

    /**
     * "Back to …" on a customer environment's own host, where these pages keep the
     * environment's console chrome: the same origin as on the login host, and nothing when
     * there is none. There is no console to fall back to there: a person of the environment
     * whose home IS this page would be sent round in a circle.
     *
     * @return array{url: string, label: string}|null
     */
    public function tenantBack(Request $request): ?array
    {
        if ($this->surfaces->current($request) !== Surface::Tenant
            || ! $this->user->check()
            || $this->surfaces->kindOf($request) !== PathKind::Account) {
            return null;
        }

        return $this->origin($request);
    }

    /**
     * Where the person came from, if anywhere this deployment trusts: an app's
     * authorization waiting in this session, else the address an app or the console sent
     * along or the page that linked here ({@see ReturnUrl}), validated and remembered for
     * the person's next page.
     *
     * @return array{url: string, label: string}|null
     */
    private function origin(Request $request): ?array
    {
        $return = $this->returns->remember($request);

        // An app's authorization waiting in this session comes first: `/oauth/authorize`
        // held the person on one of these pages (a second factor to enrol), and "Back to"
        // is then the way on, through the page that follows the waiting request.
        $pending = app(AppSignIns::class)->pending($request);

        return match (true) {
            $pending !== null => ['url' => route('app.continue'), 'label' => $pending->clientName],
            $return !== null => ['url' => $return['url'], 'label' => $return['label']],
            default => null,
        };
    }

    /**
     * The `shell` prop on the login host: console-kit's shell for this person, with the
     * rail narrowed to their own pages. Null where {@see toProps()} is.
     *
     * @return array<string, mixed>|null
     */
    public function shell(Request $request): ?array
    {
        if (! $this->applies($request) || $this->surfaces->kindOf($request) !== PathKind::Account) {
            return null;
        }

        $area = $this->area();

        if ($area === null) {
            return null;
        }

        $shell = Console::shell()->build($request)->toArray();

        // EACH PAGE IS A RAIL ENTRY OF ITS OWN, with its own icon. On this host the person's
        // pages are the whole app: one "Account" entry with a second column repeating them
        // under it was a click and a column spent on saying nothing.
        $pages = array_map(static fn (NavPageProps $page): array => [
            'key' => $page->route,
            'label' => ConsoleLabels::navigation($page->label),
            // The page is on this host: a path, so the visit stays inside the app.
            'url' => route($page->route, [], false),
            'href' => route($page->route, [], false),
            'icon' => self::PAGE_ICONS[$page->route] ?? 'settings',
            'pages' => [],
        ], $area->pages);
        $active = array_values(array_filter($area->pages, static fn (NavPageProps $page): bool => $page->active))[0] ?? null;
        // Home is the first page: the person's apps where there are any, else Security.
        $home = $pages[0]['url'] ?? route('account', [], false);

        $shell['app'] = [...self::part($shell, 'app'), 'homeUrl' => $home];
        $shell['nav'] = array_filter([
            'areas' => $pages,
            'activeArea' => $active?->route,
            'activePage' => null,
            // The phone's tab bar: the first four, the rest under More.
            'tabs' => array_slice(array_column($pages, 'key'), 0, 4),
        ], static fn (mixed $value): bool => $value !== null);
        $shell['section'] = null;

        if ($active !== null) {
            $shell['page'] = ['title' => ConsoleLabels::navigation($active->label), 'route' => $active->route];
        } else {
            unset($shell['page']);
        }

        // No organization, project or environment: these pages are the person's own.
        $shell['context'] = [...self::part($shell, 'context'), 'levels' => []];
        // ⌘K goes to the person's own pages (the layout adds the preferences). The
        // console's actions and its records search are the console's, on the app host.
        $shell['palette'] = [
            'actions' => array_map(static fn (array $page): array => [
                'id' => 'account.'.$page['key'],
                'label' => $page['label'],
                'url' => $page['url'],
                'icon' => $page['icon'],
            ], $pages),
        ];
        unset($shell['create'], $shell['createForms']);
        // The account menu at the rail's foot. Paths on this host: profile is the page
        // these are, switching account is the login host's own chooser, and signing out
        // ends the session this host holds, the person's, never the console's.
        $shell['account'] = [
            ...self::part($shell, 'account'),
            'profileUrl' => route('account', [], false),
            'switchAccountUrl' => route('accounts', [], false),
            'signOutUrl' => route('logout', [], false),
        ];
        $email = $this->user->email();

        if (is_string($email) && $email !== '') {
            $shell['person'] = [...self::part($shell, 'person'), 'email' => $email];
        }

        $shell['notifications'] = ['enabled' => false];
        $shell['apps'] = [...self::part($shell, 'apps'), 'productsUrl' => 'https://cbox.dk'];
        $shell['preferences'] = array_filter([
            ...self::part($shell, 'preferences'),
            'theme' => Theme::preference() ?? 'system',
            'railPinned' => NavPin::pinned($request),
            'regionalFormat' => IdShell::regionalFormat($request->cookie(IdShell::FORMAT_COOKIE)),
            'timeZone' => IdShell::timeZone($request->cookie(IdShell::TIME_ZONE_COOKIE)),
        ], static fn (mixed $value): bool => $value !== null);

        return $shell;
    }

    /**
     * One part of the kit's shell, to build on.
     *
     * @param  array<string, mixed>  $shell
     * @return array<array-key, mixed>
     */
    private static function part(array $shell, string $key): array
    {
        return is_array($shell[$key] ?? null) ? $shell[$key] : [];
    }

    /** @return list<array{route: string, label: string, href: string, active: bool}> */
    private function pages(): array
    {
        return array_map(static fn (NavPageProps $page): array => [
            'route' => $page->route,
            'label' => ConsoleLabels::navigation($page->label),
            // The page is on this host: a path, so the visit stays inside the app.
            'href' => route($page->route, [], false),
            'active' => $page->active,
        ], $this->area()->pages ?? []);
    }

    /**
     * The console rail's own "account" area, decided by the same code ({@see ShellPayload}):
     * a module's personal page, a feature that is switched off and a page this deployment
     * does not offer are answered once, there.
     */
    private function area(): ?NavAreaProps
    {
        $shell = app(ShellPayload::class)->build();

        if (! $shell instanceof ShellProps) {
            return null;
        }

        foreach ($shell->areas as $area) {
            if ($area->key === self::AREA) {
                return $area;
            }
        }

        return null;
    }
}
