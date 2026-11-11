<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\ShellPayload;
use App\Platform\CurrentUser;
use App\Platform\Locale\ConsoleLabels;
use Illuminate\Http\Request;

/**
 * THE CHROME OF A PERSON'S OWN PAGES ON THE LOGIN HOST: no rail, no organization or
 * environment switcher, no ⌘K. The login host has no console to put around them.
 *
 * What it draws instead is small: where the person came from ("Back to …"), their own
 * pages, and the way out. Null everywhere else — the console and a tenant's host keep the
 * chrome they have — and null for a guest, whose page is a door.
 *
 *  - BACK leads to the address the console or an app sent along (`?return_to=`, validated
 *    by {@see ReturnUrl}), else to the console.
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
     * @return array{back: array{url: string, label: string}, pages: list<array{label: string, href: string, active: bool}>, signOutHref: string}|null
     */
    public function toProps(Request $request): ?array
    {
        // Only where the layout draws it: the doors and the consent screen have their own.
        if (! $this->applies($request) || $this->surfaces->kindOf($request) !== PathKind::Account) {
            return null;
        }

        $return = $this->returns->remember($request);

        return [
            'back' => $return !== null
                ? ['url' => $return['url'], 'label' => $return['label']]
                : ['url' => app(ConsoleEntry::class)->url(), 'label' => $this->returns->ownLabel()],
            'pages' => $this->pages(),
            'signOutHref' => route('logout', [], false),
        ];
    }

    /** @return list<array{label: string, href: string, active: bool}> */
    private function pages(): array
    {
        $shell = app(ShellPayload::class)->build();

        if (! $shell instanceof ShellProps) {
            return [];
        }

        foreach ($shell->areas as $area) {
            if ($area->key === self::AREA) {
                return array_map(static fn (NavPageProps $page): array => [
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
