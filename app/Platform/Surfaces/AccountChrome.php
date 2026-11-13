<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\ShellPayload;
use App\Platform\CurrentUser;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\OAuth\AppSignIns;
use Illuminate\Http\Request;

/**
 * THE CHROME OF A PERSON'S OWN PAGES ON THE LOGIN HOST: no organization or environment
 * switcher, no ⌘K. The login host has no console to put around them.
 *
 * The layout draws it with the console's own chrome (the rail, the top bar, the account
 * menu), holding only what a person needs here: where they came from ("Back to …"), their own
 * pages, and the account menu with language, theme, switching account and signing out.
 * Null everywhere else — the console and a tenant's host keep the chrome they have, the
 * tenant's host with its own "Back to …" ({@see tenantBack()}) — and null for a guest,
 * whose page is a door.
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

    /** @return list<array{route: string, label: string, href: string, active: bool}> */
    private function pages(): array
    {
        $shell = app(ShellPayload::class)->build();

        if (! $shell instanceof ShellProps) {
            return [];
        }

        foreach ($shell->areas as $area) {
            if ($area->key === self::AREA) {
                return array_map(static fn (NavPageProps $page): array => [
                    'route' => $page->route,
                    'label' => ConsoleLabels::navigation($page->label),
                    // The page is on this host: a path, so the visit stays inside the app.
                    'href' => route($page->route, [], false),
                    'active' => $page->active,
                ], $area->pages);
            }
        }

        return [];
    }
}
