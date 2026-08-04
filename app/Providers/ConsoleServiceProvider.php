<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use App\Platform\ConsoleCurrentContext;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Platform\Enums\AccountRole;
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
 * Connectors at 70). Reserved: 10 Overview · 15 Identity platform · 20 People · 30
 * Sign-in · 40 Access control · 50 Developers · 60 Connectors · 70 Logs · 80 Settings ·
 * 90 My account.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Let plugins resolve the current org/user through console-kit's CurrentContext.
        $this->app->bind(CurrentContext::class, ConsoleCurrentContext::class);

        // Where a MODULE declares a console page — for both planes at once. A singleton
        // because it is filled during provider boot and read by both rails and the
        // parity health check for the rest of the process's life.
        $this->app->singleton(ConsolePages::class);
    }

    public function boot(): void
    {
        $this->identityPlatformFeatures();

        $nav = Console::nav();

        $nav->area('overview', 'Overview', 'dashboard', 10)
            ->page('dashboard', 'Overview', order: 10)
            ->page('usage', 'Usage', order: 20)
            ->page('approvals', 'Agent approvals', order: 30);

        // What an organization has BECAUSE IT OWNS IDENTITY PROVIDERS — the projects it
        // runs, the environments under them, the keys and domains those need, and the bill
        // for the lot. This was a console of its own, on its own prefix, behind its own
        // sign-in; it is an area, and it appears for whoever the features below admit.
        //
        // Gated page-by-page rather than as a whole area, so the rail says the same thing
        // an attempted click would: a member who may read billing and nothing else sees
        // Billing alone, and an organization that owns no IdP at all — every organization
        // on every host except the root's own accounts — has no page here, so the area
        // vanishes by the same rule that already drops an area a module left empty.
        $nav->area('identity-platform', 'Identity platform', 'layers', 15)
            ->page('projects', 'Projects', feature: 'account.projects', order: 10)
            ->page('account-members', 'Account members', feature: 'account.members', order: 20)
            ->page('api-keys', 'API keys', feature: 'account.manage', order: 30)
            ->page('environment-keys', 'Environment keys', feature: 'account.environments', order: 40)
            ->page('environment-domains', 'Environment domains', feature: 'account.environments', order: 50)
            ->page('account-activity', 'Account activity', feature: 'account.members', order: 60)
            ->page('billing', 'Billing', feature: 'account.billing', order: 70)
            ->page('account-settings', 'Account settings', feature: 'account.manage', order: 80);

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
            // Between Settings and Appearance, which is where the environment rail has
            // always put it — the two rails read in the same order on purpose.
            ->page('auth-policy', 'Sign-in rules', order: 15)
            ->page('appearance', 'Appearance', order: 20);

        // Every user's own security — shown to members and admins alike (the app
        // layout gates the admin-only areas above by role, this one is universal).
        $nav->area('account', 'My account', 'key', 90)
            ->page('account', 'Security', order: 10);
    }

    /**
     * The gates on the Identity platform area, each one an ACCOUNT capability.
     *
     * Registered as console-kit features rather than checked in the layout because that
     * is the hook a page already has: the rail drops a page whose feature is inactive,
     * and an area with no pages left. Written as closures so they are evaluated per
     * render — the answer depends on who is signed in and which organization they are
     * acting on, neither of which is known at boot.
     *
     * Every one of them asks {@see ConsoleScope}, which is the console's single answer to
     * "who is acting, on which organization, and what may they do". A page's own guard
     * asks the same object, so the rail and the page cannot disagree about who is admitted.
     */
    private function identityPlatformFeatures(): void
    {
        $features = Console::features();
        $role = static fn (): ?AccountRole => app(ConsoleScope::class)->accountRole();

        $features->register('account.projects', static fn (): bool => $role() !== null);
        $features->register('account.members', static fn (): bool => $role()?->canReadMembers() === true);
        $features->register('account.manage', static fn (): bool => $role()?->canManageMembers() === true);
        $features->register('account.environments', static fn (): bool => $role()?->canManageEnvironments() === true);
        $features->register('account.billing', static fn (): bool => $role()?->canReadBilling() === true);
    }
}
