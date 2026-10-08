<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\EnforceCustomerConsole;
use App\Http\Middleware\RequireMultiTenant;
use App\Platform\Entitlements;
use Cbox\Console\Kit\Facades\Console;

/**
 * WHAT A CUSTOMER'S OWN ADMINISTRATOR GETS — the organization console on a customer's
 * environment host, where the person signed in is an end user of somebody's product who
 * happens to administer their company's organization in it.
 *
 * It offered them everything the environment console offers: apps, APIs, webhooks, inline
 * hooks, a token vault, access reviews, role conflicts, outbound provisioning, log
 * streaming, social providers, branding, usage, a setup checklist. Every one of those is
 * the PRODUCT's administration — the vendor who built on this environment runs it from
 * `/admin` — so a customer's IT admin was handed the vendor's control panel scoped to one
 * tenant, and the vendor had no way to hand them less.
 *
 * So this console is an ADMIN PORTAL, PLUS: exactly the things a customer's IT department
 * owns — Members, Enterprise SSO, Domains, Directory Sync, Roles and the Audit log, and App
 * audit logs where the organization's plan includes them — and the person's own pages
 * beside them (a landing page, the Approvals waiting on THEM, My account). Everything else
 * stays where it already was, on the environment console.
 *
 * PERMISSIONS ARE NOT IN THE LIST, and that is the product decision rather than an
 * omission: a permission is something an app enforces, so writing new ones is the app's
 * vendor's job. A customer still composes roles out of the permissions that exist — that
 * is a write on a role (`roles.permissions`), and Roles is kept.
 *
 * WITHHELD, NOT DELETED, and the reason is that these routes are shared. The same
 * organization-plane routes are the whole console on a single-tenant install (no
 * environment console exists there — {@see RequireMultiTenant}), and
 * the operator's and the workspace's console at the platform root. Deleting a route would
 * have taken apps and webhooks away from every self-hosted install in order to take them
 * away from a customer. Only a customer's environment host is narrowed; see
 * {@see ConsoleScope::atCustomerAltitude()}.
 *
 * ONE LIST, READ BY BOTH THE RAIL AND THE DOOR. {@see ShellPayload}
 * drops a page this does not keep, and {@see EnforceCustomerConsole} answers 404 for any
 * route that belongs to such a page — so the rail cannot offer a link the door refuses,
 * and a page the rail hides cannot be reached by typing its URL. That is the difference
 * from {@see WorkspaceAltitude}, which hides but serves on purpose.
 */
final class CustomerConsole
{
    /**
     * The areas this console keeps, and which of their pages. `null` keeps every page the
     * area has — My account in particular, which a module (devices) plugs its personal
     * page into and which this list must not have to know about.
     *
     * @var array<string, list<string>|null>
     */
    private const AREAS = [
        // A landing page, and the Approvals that wait on YOUR answer — the person's own,
        // like My account, rather than the organization's administration.
        'overview' => ['dashboard', 'approvals'],
        'directory' => ['directory.members', 'roles'],
        // Inbound only. Social login, the authentication policy and outbound provisioning
        // are the product's, decided once for every organization by the vendor.
        'authentication' => ['connections', 'domains', 'directories'],
        'audit' => ['audit', 'audit-logs'],
        'account' => null,
    ];

    /**
     * Pages kept only where the organization's plan includes them, by the feature
     * {@see Entitlements} names. Withheld otherwise — off the rail and 404 at the door,
     * by the same list — rather than shown with an upsell: the plan is the vendor's
     * decision, and a customer's IT department cannot act on an "upgrade" badge.
     *
     * @var array<string, string>
     */
    private const ENTITLED = [
        // The events the vendor's app sends about this organization — the Audit Logs
        // product, sold per organization.
        'audit-logs' => 'audit_logs',
    ];

    /**
     * Routes that belong to no page on the rail and are withheld anyway.
     *
     * The setup guide is reached from the dashboard's checklist rather than from the rail,
     * and every step in it is about configuring the product — apps, branding, webhooks —
     * which this console no longer offers.
     *
     * @var list<string>
     */
    private const WITHHELD = [
        'get-started',
        'get-started.dismiss',
        'dashboard.checklist.dismiss',
    ];

    /** Whether an area has any place in a customer's console. */
    public static function keepsArea(string $area): bool
    {
        return array_key_exists($area, self::AREAS);
    }

    /** Whether one page of an area does. */
    public static function keepsPage(string $area, string $route): bool
    {
        if (! self::keepsArea($area)) {
            return false;
        }

        $pages = self::AREAS[$area];

        if ($pages !== null && ! in_array($route, $pages, true)) {
            return false;
        }

        $feature = self::ENTITLED[$route] ?? null;

        return $feature === null || app(ConsoleScope::class)->entitled($feature);
    }

    /**
     * Whether a customer's console serves a ROUTE — a page, or a write one of them makes.
     *
     * A route belongs to the rail page whose name it is or extends (`clients.show` is the
     * Apps page's, `directory.members.invite` the Members page's), the most specific one
     * winning so `account.api-keys` is judged as its own page rather than as `account`'s.
     * A route that belongs to no page at all — signing in, a step-up, switching account,
     * approving a device — is a ceremony every console needs, and is served.
     *
     * Read from the nav registry rather than from a second list, so a module that adds a
     * page to an area this console does not keep is withheld here by the same rule that
     * leaves it off the rail, without the module having to know this console exists.
     */
    public static function servesRoute(string $route): bool
    {
        if (in_array($route, self::WITHHELD, true)) {
            return false;
        }

        $owner = self::pageOf($route);

        return $owner === null || self::keepsPage($owner[0], $owner[1]);
    }

    /**
     * The rail page a route belongs to, as `[area key, page route]`, or null for a route
     * that belongs to no page — the most specific page winning, as {@see servesRoute()}
     * describes.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function pageOf(string $route): ?array
    {
        $owner = null;

        foreach (Console::nav()->areas() as $area) {
            foreach ($area->pages() as $page) {
                $belongs = $route === $page->route || str_starts_with($route, $page->route.'.');

                if ($belongs && ($owner === null || strlen($page->route) > strlen($owner[1]))) {
                    $owner = [$area->key, $page->route];
                }
            }
        }

        return $owner;
    }
}
