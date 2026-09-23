<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * WHAT A WORKSPACE'S OWN CONSOLE SHOWS — the console a customer signs in to at the
 * platform root, where the organization they administer is their Cbox workspace.
 *
 * That organization lives in Cbox's own root environment, so the one console offered it
 * everything an organization in any environment has: roles, apps, webhooks, inline hooks,
 * a token vault, connectors, access reviews. Every one of those administered the
 * workspace's own record in Cbox's environment — which nobody signs in to except the
 * workspace's team — while the customer's actual product sat in an environment console on
 * another host, reachable only through Projects › Open. The rail looked like their product
 * and was not.
 *
 * So at this altitude the rail is the workspace and nothing else: its projects, team, keys,
 * billing and settings; how its own team signs in to Cbox (single sign-on and sign-in
 * rules); its activity log; and the person's own account.
 *
 * HIDDEN, NOT REDIRECTED — and the difference was a decision:
 *
 *  - Nothing is stranded. A workspace that registered an app or a webhook in the root
 *    environment before this change can still reach it by URL and delete it. A redirect
 *    would have made that data unreachable without making it go away.
 *  - The pages stay authorized by one thing, {@see ConsoleScope}. A redirect is a second
 *    gate that has to agree with the first on every route forever; a filter on what the
 *    rail offers cannot lock anybody out of anything.
 *  - A page reached this way does not pretend: the shell says above it that this is the
 *    workspace's own Cbox record and points at Projects, where the product is.
 *
 * NOT AT THIS ALTITUDE: an operator (the full console is their job), any host other than
 * the platform root, and a single-tenant install — there the root environment IS the
 * product, and every one of these pages is the one the owner came for.
 */
final class WorkspaceAltitude
{
    /**
     * The areas a workspace's console keeps, and which of their pages. `null` keeps every
     * page the area has — the Workspace area in particular, which a module (billing)
     * plugs into and which this list must not have to know about.
     *
     * @var array<string, list<string>|null>
     */
    private const AREAS = [
        'identity-platform' => null,
        // The workspace team's OWN sign-in to Cbox: single sign-on and sign-in rules.
        // Social sign-in and directory sync are for the people signing in to a product.
        'authentication' => ['connections', 'auth-policy'],
        'audit' => ['audit'],
        'account' => null,
    ];

    /**
     * What an area is called at this altitude, where it names something narrower than it
     * does elsewhere.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'authentication' => 'Team sign-in',
    ];

    /** Whether an area has any place in a workspace's console. */
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

    public static function label(string $area, string $default): string
    {
        return self::LABELS[$area] ?? $default;
    }
}
