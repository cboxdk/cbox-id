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
        $nav->area('directory', 'People', 'members', 20)
            ->page('members', 'Members', order: 10)
            ->page('roles', 'Roles', order: 20);

        $nav->area('authentication', 'Sign-in', 'connections', 30)
            ->page('connections', 'Single sign-on', order: 10)
            ->page('sso-providers', 'Login methods', order: 20)
            ->page('directories', 'User sync', order: 30)
            ->page('provisioning', 'Outbound sync', order: 40);

        $nav->area('governance', 'Access control', 'shield', 40)
            ->page('governance', 'Access reviews', order: 10)
            ->page('sod-policies', 'Conflict rules', order: 20);

        $nav->area('developers', 'Developers', 'clients', 50)
            ->page('clients', 'Apps & API keys', order: 10)
            ->page('webhooks', 'Webhooks', order: 20)
            ->page('hooks', 'Event hooks', order: 30)
            ->page('vault', 'Stored tokens', order: 40);

        $nav->area('audit', 'Logs', 'audit', 60)
            ->page('audit', 'Activity log', order: 10)
            ->page('audit-streams', 'Log streaming', order: 20);

        $nav->area('settings', 'Settings', 'settings', 70)
            ->page('settings', 'Settings', order: 10)
            ->page('appearance', 'Appearance', order: 20);

        // Every user's own security — shown to members and admins alike (the app
        // layout gates the admin-only areas above by role, this one is universal).
        $nav->area('account', 'My account', 'key', 90)
            ->page('account', 'Security', order: 10);
    }
}
