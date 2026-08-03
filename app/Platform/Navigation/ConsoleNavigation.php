<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use App\Platform\ConsoleLocation;
use Cbox\Id\Platform\Enums\AccountRole;

/**
 * The navigation of every plane except the organization console, which is assembled at
 * runtime from the plugin registry (see {@see ConsoleLocation}).
 *
 * The layouts render from these; so does the eyebrow above every page title, so the two
 * cannot disagree. Adding a page means adding it here, once.
 */
class ConsoleNavigation
{
    /**
     * The workspace (account) console — what an account member sees before choosing an
     * environment.
     *
     * Role-aware: a member who cannot read billing has no Billing page, and an area
     * left with no pages disappears rather than rendering an empty rail icon. Null role
     * means no member is resolved yet, which shows only the pages that need no
     * permission at all.
     */
    public function workspace(?AccountRole $role): ConsoleNav
    {
        $areas = [
            new NavArea('Overview', 'dashboard',
                new NavPage('workspace.home', 'Projects'),
            ),
            new NavArea('People', 'members',
                ...($role?->canReadMembers() === true ? [new NavPage('workspace.members', 'Members')] : []),
            ),
            new NavArea('Developers', 'clients',
                ...($role?->canManageMembers() === true ? [new NavPage('workspace.api-keys', 'API keys')] : []),
                ...($role?->canManageEnvironments() === true ? [
                    new NavPage('workspace.environment-keys', 'Environment keys'),
                    new NavPage('workspace.environment-domains', 'Domains'),
                ] : []),
            ),
            new NavArea('Account', 'settings',
                ...($role?->canReadMembers() === true ? [new NavPage('workspace.activity', 'Activity')] : []),
                ...($role?->canReadBilling() === true ? [new NavPage('workspace.billing', 'Billing')] : []),
                ...($role?->canManageMembers() === true ? [new NavPage('workspace.settings', 'Settings')] : []),
            ),
            // The member's OWN identity, 2FA and passkeys — a personal concern rather
            // than an account setting, so it gets its own area instead of hiding at the
            // bottom of Settings.
            new NavArea('Personal', 'shield-check',
                new NavPage('workspace.security', 'Profile'),
            ),
        ];

        return new ConsoleNav(...array_filter($areas, fn (NavArea $area): bool => $area->pages !== []));
    }

    /**
     * The environment control plane — an account-member admin's view of ONE
     * environment. Every resource here is environment-scoped.
     */
    public function environment(): ConsoleNav
    {
        return new ConsoleNav(
            new NavArea('Overview', 'dashboard',
                new NavPage('environment.home', 'Overview'),
                new NavPage('environment.analytics', 'Analytics'),
                new NavPage('environment.approvals', 'Agent approvals'),
            ),
            new NavArea('Tenants', 'layers',
                new NavPage('environment.organizations', 'Organizations'),
            ),
            new NavArea('People', 'members',
                new NavPage('environment.users', 'Users'),
                new NavPage('environment.roles', 'Roles'),
                new NavPage('environment.permissions', 'Permissions'),
            ),
            new NavArea('Sign-in', 'connections',
                new NavPage('environment.connections', 'Single sign-on'),
                new NavPage('environment.sso-providers', 'Login methods'),
                new NavPage('environment.directories', 'Directories'),
                new NavPage('environment.provisioning', 'Outbound sync'),
            ),
            new NavArea('Access control', 'shield-check',
                new NavPage('environment.governance', 'Access reviews'),
                // One component serves both planes now, so it has one title — and the
                // organization plane's "Role conflicts" is the name the help topic and
                // the published guide already use.
                new NavPage('environment.sod-policies', 'Role conflicts'),
            ),
            new NavArea('Developers', 'clients',
                new NavPage('environment.clients', 'Applications'),
                new NavPage('environment.webhooks', 'Webhooks'),
                // "Inline hooks" on both planes now. Called "Event hooks" here, it sat
                // one line under Webhooks — a different capability that runs after the
                // fact — and named the synchronous one after the asynchronous one.
                new NavPage('environment.hooks', 'Inline hooks'),
                new NavPage('environment.vault', 'Stored tokens'),
            ),
            new NavArea('Logs', 'audit',
                new NavPage('environment.audit', 'Audit log'),
                new NavPage('environment.audit-streams', 'Log streaming'),
            ),
            new NavArea('Settings', 'settings',
                new NavPage('environment.settings', 'Settings'),
                new NavPage('environment.auth-policy', 'Sign-in rules'),
                new NavPage('environment.appearance', 'Appearance'),
            ),
        );
    }

    /** The operator plane — whoever runs this deployment, above every account. */
    public function operator(): ConsoleNav
    {
        return new ConsoleNav(
            new NavArea('Platform', 'layers',
                new NavPage('operator.environments', 'Environments'),
                new NavPage('operator.accounts', 'Accounts'),
                new NavPage('operator.organizations', 'Organizations'),
            ),
            new NavArea('Insights', 'dashboard',
                new NavPage('operator.usage', 'Usage'),
                new NavPage('operator.search', 'Search'),
            ),
            new NavArea('Administration', 'shield',
                new NavPage('operator.operators', 'Operators'),
                new NavPage('operator.security', 'Security'),
            ),
        );
    }

    /**
     * Every plane's navigation, for the code that has only a route name and needs to
     * find which plane owns it. The workspace nav is taken at its widest role, because
     * the question being asked is "where does this page live", not "may you see it".
     *
     * @return list<ConsoleNav>
     */
    public function all(): array
    {
        return [
            $this->workspace(AccountRole::Owner),
            $this->environment(),
            $this->operator(),
        ];
    }
}
