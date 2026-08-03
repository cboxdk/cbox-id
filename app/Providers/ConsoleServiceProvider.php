<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\ConsoleCurrentContext;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Support\ServiceProvider;

/**
 * Seeds the console's built-in navigation into the shared {@see Console} nav registry.
 * The layout renders from the registry, so an optional plugin (billing, …) can add its
 * own area/pages — or extend one of these — purely by being installed, no host edit.
 *
 * A page's console-kit `feature` is a hard presence gate (hidden when the feature is
 * not active). The entitlement soft-lock on SSO/SCIM (shown, but badged when the org
 * isn't entitled) stays an app concern in the layout — a different gate.
 *
 * AREA ORDERS ARE UNIQUE, ACROSS MODULES TOO. {@see DefaultNavRegistry::areas()} sorts
 * on `order` alone, so two areas sharing a number resolve by provider boot order — the
 * rail silently reorders itself when a module is enabled, disabled, or the config cache
 * is rebuilt. The console shipped two such ties (Logs/Security at 60, Settings/
 * Connectors at 70). Reserved: 10 Overview · 20 People · 30 Sign-in · 40 Access control
 * · 50 Developers · 60 Connectors · 70 Logs · 80 Settings · 90 My account.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Let plugins resolve the current org/user through console-kit's CurrentContext.
        $this->app->bind(CurrentContext::class, ConsoleCurrentContext::class);
    }

    public function boot(): void
    {
        $nav = Console::nav();

        $nav->area('overview', 'Overview', 'dashboard', 10)
            ->page('dashboard', 'Overview', order: 10)
            ->page('usage', 'Usage', order: 20)
            ->page('approvals', 'Agent approvals', order: 30);

        // Plain-language labels for non-experts (the technical term lives on the page
        // header, not the nav). "Directory" → People, "Authentication" → Sign-in, etc.
        //
        // A LABEL HERE IS A PROMISE: it must be the same string as the page's own <h1>
        // and browser title. Clicking "Stored tokens" and landing on a page titled
        // "Token vault" makes a user doubt they arrived where they aimed — the console
        // shipped six such mismatches, and they read as the product being confusing
        // rather than merely inconsistent. Rename in both places, or in neither.
        $nav->area('directory', 'People', 'members', 20)
            ->page('members', 'Members', order: 10)
            ->page('roles', 'Roles', order: 20);

        // "Sync users in" / "Sync users out" — the two SCIM directions are a pair, and
        // are only comprehensible as one. "User sync" beside "Outbound sync" gave no
        // clue which way either moved people.
        $nav->area('authentication', 'Sign-in', 'connections', 30)
            ->page('connections', 'Single sign-on', order: 10)
            ->page('social-providers', 'Social sign-in', order: 20)
            ->page('directories', 'Sync users in', order: 30)
            ->page('provisioning', 'Sync users out', order: 40);

        $nav->area('governance', 'Access control', 'shield', 40)
            ->page('governance', 'Access reviews', order: 10)
            ->page('sod-policies', 'Role conflicts', order: 20);

        $nav->area('developers', 'Developers', 'clients', 50)
            ->page('clients', 'Apps & API keys', order: 10)
            ->page('webhooks', 'Webhooks', order: 20)
            ->page('hooks', 'Inline hooks', order: 30)
            ->page('vault', 'Token vault', order: 40);

        // 60 is left to the connectors module; the compliance and risk modules append
        // their pages to this area rather than minting their own (see below).
        $nav->area('audit', 'Logs', 'audit', 70)
            ->page('audit', 'Activity log', order: 10);

        $nav->area('settings', 'Settings', 'settings', 80)
            ->page('settings', 'Settings', order: 10)
            ->page('appearance', 'Appearance', order: 20);

        // Every user's own security — shown to members and admins alike (the app
        // layout gates the admin-only areas above by role, this one is universal).
        $nav->area('account', 'My account', 'key', 90)
            ->page('account', 'Security', order: 10);
    }
}
