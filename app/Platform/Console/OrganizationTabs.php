<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shared\LinkTabProps;

/**
 * THE ORGANIZATION PAGE'S TABS — each one a page of its own, with its own URL under
 * `/admin/organizations/{organization}/…`.
 *
 * WHY THERE IS A HUB AT ALL. Everything the environment console does to one customer used
 * to be done by first choosing that customer in the console header, which turned sixteen
 * environment pages into that customer's pages until somebody remembered to choose again.
 * Setting up SSO for Acme meant: pick Acme up there, open Enterprise SSO, and hope nobody
 * changed the pick in another tab. An organization is a THING, so it gets a page, and what
 * belongs to it hangs off that page's address — the same shape an app's page has
 * ({@see AppTabs}) and for the same reason: a link means one page to everybody who opens it.
 *
 * The environment-wide lists (Enterprise SSO, Roles, Audit log…) stay where they were, for
 * every organization at once with an Organization column and a filter. The tabs here are
 * the same pages narrowed to this one, plus the ones that only ever made sense for one —
 * its people, its invitations, its domains.
 *
 * The route each tab is is the one fact here; {@see self::currentFor()} reads a request's
 * route name back to the tab it is.
 */
final readonly class OrganizationTabs
{
    public const OVERVIEW = 'overview';

    public const MEMBERS = 'members';

    public const INVITATIONS = 'invitations';

    public const SSO = 'sso';

    public const DIRECTORY_SYNC = 'directory-sync';

    public const DOMAINS = 'domains';

    public const ROLES = 'roles';

    public const API_KEYS = 'api-keys';

    public const BRANDING = 'branding';

    public const POLICY = 'policy';

    public const SUPPORT = 'support';

    public const AUDIT = 'audit';

    public const AUDIT_LOGS = 'audit-logs';

    public const SETTINGS = 'settings';

    /**
     * Tab key => [label, route name]. In the order they are drawn.
     *
     * The label is the page's own name — what it is called where it is the only tab drawn
     * (a sub-tab), and what a link to it by key says.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TABS = [
        self::OVERVIEW => ['Overview', 'environment.organizations.show'],
        self::MEMBERS => ['Members', 'environment.organizations.members'],
        self::INVITATIONS => ['Invitations', 'environment.organizations.invitations'],
        self::SSO => ['SSO', 'environment.organizations.sso'],
        self::DIRECTORY_SYNC => ['Directory Sync', 'environment.organizations.directory-sync'],
        self::DOMAINS => ['Domains', 'environment.organizations.domains'],
        self::ROLES => ['Roles', 'environment.organizations.roles'],
        self::POLICY => [Vocabulary::AUTHENTICATION_POLICY, 'environment.organizations.policy'],
        self::AUDIT => ['Audit log', 'environment.organizations.audit'],
        self::AUDIT_LOGS => ['App audit logs', 'environment.organizations.audit-logs'],
        self::SETTINGS => ['General', 'environment.organizations.settings'],
        self::BRANDING => ['Branding', 'environment.organizations.branding'],
        self::API_KEYS => ['API keys', 'environment.organizations.api-keys'],
        self::SUPPORT => ['Support access', 'environment.organizations.support'],
    ];

    /**
     * THE ROW A PERSON READS — nine tabs, each a group of the pages above.
     *
     * Fourteen tabs used to sit in one row, and at a laptop's width the last two — App audit
     * logs and Settings — were cut off the edge of the page: a row that has to be scrolled
     * sideways to find Settings is a row nobody finds Settings in. So pages that are the
     * same subject share one tab, and the tab draws the group's pages as a second, smaller
     * row beneath it: Members beside its pending Invitations, the Audit log beside the App
     * audit logs, and the organization's housekeeping — its details, branding, member API
     * keys and support access — under Settings, where every other console puts them.
     *
     * The enterprise trio (SSO, Directory Sync, Domains), Roles and the authentication policy stay
     * a click away each, because those are what an environment administrator opens an
     * organization to do. Every page keeps its URL; only where its link is drawn changed.
     *
     * Group key => [the tab's label, its pages in order]. The first page is where the tab
     * lands.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const GROUPS = [
        self::OVERVIEW => ['Overview', [self::OVERVIEW]],
        self::MEMBERS => ['Members', [self::MEMBERS, self::INVITATIONS]],
        self::SSO => ['SSO', [self::SSO]],
        self::DIRECTORY_SYNC => ['Directory Sync', [self::DIRECTORY_SYNC]],
        self::DOMAINS => ['Domains', [self::DOMAINS]],
        self::ROLES => ['Roles', [self::ROLES]],
        self::POLICY => [Vocabulary::AUTHENTICATION_POLICY, [self::POLICY]],
        self::AUDIT => ['Audit log', [self::AUDIT, self::AUDIT_LOGS]],
        self::SETTINGS => ['Settings', [self::SETTINGS, self::BRANDING, self::API_KEYS, self::SUPPORT]],
    ];

    /**
     * The tab row: one tab per group, lit when the page shown is any of the group's.
     *
     * @return list<LinkTabProps>
     */
    public function for(string $organizationId, string $current): array
    {
        $tabs = [];

        foreach (self::GROUPS as $group => [$label, $pages]) {
            $tabs[] = new LinkTabProps(
                $group,
                $label,
                route(self::TABS[$pages[0]][1], ['organization' => $organizationId]),
                in_array($current, $pages, true),
            );
        }

        return $tabs;
    }

    /**
     * The second row: the pages of the group the shown page belongs to, or nothing when that
     * group is one page (or the page is under the hub but in no group, like a token vault).
     *
     * @return list<LinkTabProps>
     */
    public function subTabsFor(string $organizationId, string $current): array
    {
        foreach (self::GROUPS as [, $pages]) {
            if (! in_array($current, $pages, true) || count($pages) < 2) {
                continue;
            }

            return array_map(static fn (string $key): LinkTabProps => new LinkTabProps(
                $key,
                self::TABS[$key][0],
                route(self::TABS[$key][1], ['organization' => $organizationId]),
                $key === $current,
            ), $pages);
        }

        return [];
    }

    /**
     * The tab a route IS, or '' for a page under the hub that is not one of them (an
     * organization's token vault): it draws the header and lights no tab.
     */
    public static function currentFor(?string $routeName): string
    {
        foreach (self::TABS as $key => [, $route]) {
            if ($routeName === $route) {
                return $key;
            }
        }

        return '';
    }

    /**
     * The tab in the row a page is drawn under — itself for a one-page group, `members` for
     * Invitations, `settings` for Branding.
     */
    public static function groupOf(string $tab): ?string
    {
        foreach (self::GROUPS as $group => [, $pages]) {
            if (in_array($tab, $pages, true)) {
                return $group;
            }
        }

        return null;
    }

    /** The route each tab is, for a caller that links to one by key. */
    public static function route(string $tab): string
    {
        return self::TABS[$tab][1] ?? self::TABS[self::OVERVIEW][1];
    }
}
