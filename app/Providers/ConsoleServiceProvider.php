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

        $nav->area('directory', 'Directory', 'members', 20)
            ->page('members', 'Members', order: 10)
            ->page('roles', 'Roles', order: 20);

        $nav->area('authentication', 'Authentication', 'connections', 30)
            ->page('connections', 'SSO connections', order: 10)
            ->page('sso-providers', 'SSO providers', order: 20)
            ->page('directories', 'Directory sync', order: 30)
            ->page('provisioning', 'Outbound SCIM', order: 40);

        $nav->area('governance', 'Governance', 'shield', 40)
            ->page('governance', 'Access reviews', order: 10)
            ->page('sod-policies', 'Segregation of duties', order: 20);

        $nav->area('developers', 'Developers', 'clients', 50)
            ->page('clients', 'API clients', order: 10)
            ->page('webhooks', 'Webhooks', order: 20)
            ->page('hooks', 'Inline hooks', order: 30)
            ->page('vault', 'Token vault', order: 40);

        $nav->area('audit', 'Audit', 'audit', 60)
            ->page('audit', 'Audit log', order: 10)
            ->page('audit-streams', 'SIEM streams', order: 20);

        $nav->area('settings', 'Settings', 'settings', 70)
            ->page('settings', 'Settings', order: 10);

        // Every user's own security — shown to members and admins alike (the app
        // layout gates the admin-only areas above by role, this one is universal).
        $nav->area('account', 'My account', 'key', 90)
            ->page('account', 'Security', order: 10);
    }
}
