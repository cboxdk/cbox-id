<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use App\Platform\ConsoleCurrentContext;
use App\Platform\OrganizationCapabilities;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Contracts\NavRegistry;
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
 * Connectors at 70). Reserved: 10 Overview · 15 Identity platform · 20 People · 30
 * Sign-in · 40 Access control · 50 Developers · 60 Connectors · 70 Logs · 80 Settings ·
 * 90 My account · 100 Platform · 110 Insights · 120 Administration.
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
            ->page('projects', 'Projects', feature: 'organization.projects', order: 10)
            // ADMINISTRATORS, not "Members". Two areas carried a page labelled "Members"
            // pointing at two different components, which is how they came to share a
            // route without anyone noticing. A label is a promise: this one is the people
            // who ADMINISTER the organization, and the page's own heading says so.
            ->page('members', 'Administrators', feature: 'organization.members', order: 20)
            ->page('api-keys', 'API keys', feature: 'organization.manage', order: 30)
            ->page('environment-keys', 'Environment keys', feature: 'organization.environments', order: 40)
            ->page('environment-domains', 'Environment domains', feature: 'organization.environments', order: 50)
            // 70 is the BILLING module's, added by its own provider — see modules/billing.
            // Left as a gap rather than closed up: the orders in this area are unique
            // across modules by contract, and renumbering to fill it would collide with a
            // module the host cannot see.
            ->page('organization-settings', 'Organization settings', feature: 'organization.manage', order: 80);

        // Plain-language labels for non-experts (the technical term lives on the page
        // header, not the nav). "Directory" → People, "Authentication" → Sign-in, etc.
        //
        // A LABEL HERE IS A PROMISE: it must be the same string as the page's own <h1>
        // and browser title. Clicking "Stored tokens" and landing on a page titled
        // "Token vault" makes a user doubt they arrived where they aimed — the console
        // shipped six such mismatches, and they read as the product being confusing
        // rather than merely inconsistent. Rename in both places, or in neither.
        $nav->area('directory', 'People', 'members', 20)
            ->page('directory.members', 'Members', order: 10)
            ->page('roles', 'Roles', order: 20)
            // Roles are made OF permissions, so a plane that offers one and hides the
            // other asks an administrator to assign a thing they cannot inspect. It was
            // environment-plane-only — the same component, reachable from one console.
            ->page('permissions', 'Permissions', order: 30);

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
            ->page('frontend-keys', 'Frontend keys', order: 15)
            ->page('legacy-login', 'Legacy login', order: 17)
            ->page('webhooks', 'Webhooks', order: 20)
            ->page('hooks', 'Inline hooks', order: 30)
            ->page('vault', 'Token vault', order: 40);

        // 60 is left to the connectors module; the compliance and risk modules append
        // their pages to this area rather than minting their own (see below).
        $nav->area('audit', 'Logs', 'audit', 70)
            ->page('audit', 'Activity log', order: 10)
            // Environment-plane-only until now. Shipping the audit trail to a SIEM is an
            // obligation the ORGANIZATION carries, so hiding it from the organization
            // plane meant the party answerable for it could not see whether it ran.
            ->page('audit-streams', 'Log streaming', order: 20);

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

        $this->platformAreas($nav);
    }

    /**
     * THE PLATFORM SECTION — whoever runs this deployment, standing above every customer.
     *
     * These pages had a console of their own: their own prefix, their own layout
     * (`layouts/platform`), their own navigation tree, built by hand in
     * `ConsoleNavigation::operator()`. Signing in at the root host and clicking through to
     * `/platform` read as arriving somewhere else — a second product — when it is the same
     * person, the same session, and the same rail with three more areas on it.
     *
     * So they are areas here, seeded into the same registry as everything above, and the
     * one console renders them exactly the way it renders Billing or Webhooks. What made
     * that possible is that the operator is a SUBJECT: `platform_operators` used to be a
     * second credential store, and with no way to ask "is this session staff" the only way
     * to gate these pages was to put a different door — and therefore a different shell —
     * in front of them. The question has an answer now, so the gate is a feature like any
     * other and the shell is the shell everybody else gets.
     *
     * ORDERED LAST, BELOW `My account`, because that is what they are: extra items for the
     * few people who administer the install, appended to the console every customer sees.
     *
     * ROOT FIRST, THEN LEAF, inside the Platform area. The hierarchy is customer → project
     * → environment, and this list used to read the other way (Environments, Customers,
     * Organizations — the leaf, then the root, then a tenant inside the leaf). An operator
     * was handed six planes called `production`, `staging`, `acme`, `acme-staging`,
     * `billing-portal` and `demo-co` with no way to tell that `billing-portal` is Acme's.
     */
    private function platformAreas(NavRegistry $nav): void
    {
        // ICONS DISTINCT AT 18px, which is the only size the collapsed rail draws them at
        // — and the reason `rocket` rather than the `layers` this area carried in its own
        // shell. It never shared a rail with `identity-platform` before, and that area is
        // `layers` too: side by side they are one glyph appearing twice in a control whose
        // whole job is to be told apart at a glance. Insights takes `chart` and
        // Administration `lock`, both unused by the areas above.
        $nav->area('platform', 'Platform', 'rocket', 100)
            ->page('platform.customers', 'Customers', feature: 'platform.operator', order: 10)
            ->page('platform.environments', 'Environments', feature: 'platform.operator', order: 20)
            ->page('platform.organizations', 'Organizations', feature: 'platform.operator', order: 30);

        $nav->area('platform-insights', 'Insights', 'chart', 110)
            ->page('platform.usage', 'Usage', feature: 'platform.operator', order: 10)
            ->page('platform.search', 'Search', feature: 'platform.operator', order: 20);

        // "Security" is gone, and its absence is the fix. It enrolled a SEPARATE operator
        // TOTP factor that nothing ever verified: the operator sign-in door was retired
        // when operators became ordinary subjects, so `OperatorMfa` had no reader left
        // anywhere in the app or the framework — grep it and only the enrolment screen and
        // its own implementation come back. An operator scanned a QR code, saw "enabled",
        // pocketed recovery codes, and was protected by nothing, on the most privileged
        // account on the deployment. The page even said so in its subtitle: "the second
        // factor that protects everything on the Platform rail."
        //
        // The real factor is the subject's own, on `/account`, which `PlatformAuth`
        // actually checks at sign-in. One person, one identity, one second factor.
        $nav->area('platform-admin', 'Administration', 'lock', 120)
            ->page('platform.operators', 'Operators', feature: 'platform.operator', order: 10);
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
        $can = static fn (): ?OrganizationCapabilities => app(ConsoleScope::class)->capabilities();

        // The area itself, gated on HOLDING an account role rather than on any one
        // capability: a person who administers this account belongs in the area even if
        // every page inside it happens to be closed to them, and `ownsIdentityProviders()`
        // is the same question phrased for the layout.
        $features->register('organization.projects', static fn (): bool => app(ConsoleScope::class)->ownsIdentityProviders());
        $features->register('organization.members', static fn (): bool => $can()?->canReadMembers() === true);
        $features->register('organization.manage', static fn (): bool => $can()?->canManageMembers() === true);
        $features->register('organization.environments', static fn (): bool => $can()?->canManageEnvironments() === true);
        // THE PLATFORM SECTION'S GATE, and the only thing standing between an ordinary
        // customer and the pages that suspend customers. It is deliberately the same
        // mechanism as every gate above rather than a stronger-looking one: a feature that
        // is false drops the page, and an area with no pages left is dropped whole, so a
        // non-operator's rail does not render the areas at all.
        //
        // THE RAIL IS NOT THE AUTHORIZATION. Each of these pages calls
        // {@see ConsoleScope::assertPlatformOperator()} in its own `boot()`, which is what
        // actually refuses the request; this only decides whether the rail offers a link
        // to a page the visitor would be turned away from. Both ask the same ConsoleScope,
        // which is why they cannot disagree about who is staff.
        $features->register('platform.operator', static fn (): bool => app(ConsoleScope::class)->isPlatformOperator());
    }
}
