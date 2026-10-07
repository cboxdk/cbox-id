<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\EnforceCustomerConsole;
use App\Http\Middleware\RequireMultiTenant;
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
 * So this console is an ADMIN PORTAL, PLUS: the things a customer's IT department actually
 * owns — its members, its single sign-on and the domains that route to it, its directory
 * sync, its roles and the permissions they are made of, its audit log — and the person's
 * own pages beside them. Everything else stays where it already was, on the environment
 * console.
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
        // A landing page, and the requests to act as YOU that wait on your answer.
        'overview' => ['dashboard', 'approvals'],
        'directory' => ['directory.members', 'roles', 'permissions'],
        // Single sign-on carries its verified domains on the same page; directory sync is
        // "Sync users in". Social sign-in, sign-in rules and outbound sync are the product's.
        'authentication' => ['connections', 'directories'],
        'audit' => ['audit'],
        'account' => null,
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

        return $pages === null || in_array($route, $pages, true);
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

        $owner = null;

        foreach (Console::nav()->areas() as $area) {
            foreach ($area->pages() as $page) {
                $belongs = $route === $page->route || str_starts_with($route, $page->route.'.');

                if ($belongs && ($owner === null || strlen($page->route) > strlen($owner[1]))) {
                    $owner = [$area->key, $page->route];
                }
            }
        }

        return $owner === null || self::keepsPage($owner[0], $owner[1]);
    }
}
