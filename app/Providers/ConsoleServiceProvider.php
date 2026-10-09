<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\ApiKeys\ApiKeyPresence;
use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\Vocabulary;
use App\Platform\ConsoleCurrentContext;
use App\Platform\CurrentUser;
use App\Platform\OrganizationCapabilities;
use Cbox\Console\Kit\Contracts\CurrentContext;
use Cbox\Console\Kit\Contracts\NavRegistry;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
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
 * Connectors at 70). Reserved: 10 Overview · 15 Workspace · 20 People · 30
 * Sign-in · 40 Access control · 50 Developers · 60 Connectors · 70 Audit log · 80 Settings ·
 * 90 My account · 100 Platform · 110 Insights · 120 Administration.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Let plugins resolve the current org/user through console-kit's CurrentContext.
        $this->app->bind(CurrentContext::class, ConsoleCurrentContext::class);

        // Where a MODULE declares a console page — for both planes at once. A singleton
        // because it is filled during provider boot and read by both rails for the rest
        // of the process's life.
        $this->app->singleton(ConsolePages::class);

        // The API key pages' rail gates, answered once per request (see ApiKeyPresence).
        $this->app->scoped(ApiKeyPresence::class);
    }

    public function boot(): void
    {
        $this->identityPlatformFeatures();

        $nav = Console::nav();

        // ONE RAIL, THREE CONSOLES. Everything below is the organization console in full —
        // what a single-tenant install and an operator get. Two altitudes draw less of it,
        // each from a list of its own: a workspace at the platform root
        // ({@see \App\Platform\Console\WorkspaceAltitude}) and a customer's administrator on
        // a customer's environment host ({@see \App\Platform\Console\CustomerConsole}), who
        // gets an admin portal and their own pages. An area added here appears on neither
        // of those until its list says so.

        // OVERVIEW IS THE ONE AREA THAT MIXES BOTH KINDS. The rail's role gate works on
        // whole AREAS — a plain member sees `overview`, `account` and nothing else — while
        // Usage inside it calls `assertMayAdminister()` in its own boot(). So a member was
        // shown a link to a page that answered 403: present-and-refusing, which reads like
        // a permissions problem somebody will go and try to fix, and is the worse failure
        // of the two available. Gated page-by-page now, on the same question the page
        // asks, which is why they cannot disagree.
        $nav->area('overview', 'Overview', 'dashboard', 10)
            ->page('dashboard', 'Overview', order: 10)
            ->page('usage', 'Usage', feature: 'organization.usage', order: 20)
            // ONE WORD ON BOTH CONSOLES, for two pages. This one is where the signed-in
            // person approves or denies a request to act as THEM; the environment console's
            // lists every pending request in the environment so an administrator can deny
            // abuse. They were "Approve agent requests" and "Review agent requests" — two
            // phrases to learn for one queue — and the page header says whose it is.
            ->page('approvals', Vocabulary::APPROVALS, order: 30);

        // THE WORKSPACE — the customer's own Cbox account: the projects it runs, the
        // environments under them, the team that administers them, the keys and domains
        // those need, and the bill for the lot. It was labelled "Identity platform", with
        // "Administrators" and "Account settings" inside it, which gave one thing three
        // names on one rail. See docs/core-concepts/workspaces-and-organizations.md.
        //
        // Gated page-by-page rather than as a whole area, so the rail says the same thing
        // an attempted click would: a member who may read billing and nothing else sees
        // Billing alone, and an organization that owns no IdP at all — every organization
        // on every host except the root's own workspaces — has no page here, so the area
        // vanishes by the same rule that already drops an area a module left empty.
        //
        // The area KEY stays `identity-platform`: it is the socket the billing module
        // plugs into, and a key is not something a person reads.
        $nav->area('identity-platform', 'Workspace', 'briefcase', 15)
            ->page('projects', 'Projects', feature: 'organization.projects', order: 10)
            // TEAM — the people who administer the workspace. Not "Members", which is the
            // People page of every organization, and not "Administrators", which a Viewer on
            // the list is not.
            ->page('members', Vocabulary::TEAM, feature: 'organization.members', order: 20)
            // One page, the kind of key as a tab: secret keys for an environment first, the
            // workspace's own keys second. Gated on the first tab's capability, which every
            // role that may see the second also holds. My account's page is "My API keys",
            // so the same words never sit twice on one rail.
            ->page('keys', Vocabulary::API_KEYS, feature: 'organization.environments', order: 30)
            ->page('environment-domains', 'Environment domains', feature: 'organization.environments', order: 50)
            // 70 is the BILLING module's, added by its own provider — see modules/billing.
            // Left as a gap rather than closed up: the orders in this area are unique
            // across modules by contract, and renumbering to fill it would collide with a
            // module the host cannot see.
            ->page('organization-settings', 'Workspace settings', feature: 'organization.manage', order: 80);

        // Plain-language labels for non-experts (the technical term lives on the page
        // header, not the nav). "Directory" → Members & roles, "Authentication" → Sign-in.
        //
        // MEMBERS & ROLES, not "People": the people in ONE organization are its members,
        // which is the word every page here uses, and the rest of the area is what they
        // hold — the environment console's "Users & orgs" is the same shape. The
        // workspace's own colleagues are its Team, one area up; "People" named neither and
        // was read as both.
        //
        // A LABEL HERE IS A PROMISE: it must be the same string as the page's own <h1>
        // and browser title. Clicking "Stored tokens" and landing on a page titled
        // "Token vault" makes a user doubt they arrived where they aimed — the console
        // shipped six such mismatches, and they read as the product being confusing
        // rather than merely inconsistent. Rename in both places, or in neither.
        $nav->area('directory', ConsoleArea::Directory->organizationLabel(), 'members', 20)
            ->page('directory.members', Vocabulary::MEMBERS, order: 10)
            ->page('roles', Vocabulary::ROLES, order: 20)
            // Roles are made OF permissions, so a plane that offers one and hides the
            // other asks an administrator to assign a thing they cannot inspect. It was
            // environment-plane-only — the same component, reachable from one console.
            ->page('permissions', Vocabulary::PERMISSIONS, order: 30)
            // Every API key the organization's people hold for its apps. Only where an app
            // offers keys here, or somebody already holds one: on every other organization
            // it would be an empty page about a feature nobody turned on.
            ->page('directory.api-keys', Vocabulary::MEMBER_API_KEYS, feature: 'organization.api-keys', order: 40);

        // THE MARKET'S WORDS for the pages both consoles share — "Enterprise SSO",
        // "Directory Sync", "Outbound provisioning" — because one component serves both
        // planes with one title, and the environment console files them under the names a
        // person arriving from another identity platform searches for. The two SCIM
        // directions are named so neither can be mistaken for the other: Directory Sync
        // brings people in, Outbound provisioning sends them out.
        //
        // SIGN-IN RULES LIVE HERE NOW, not under Settings. They are the password, MFA and
        // session policy — a sign-in question — and on a workspace's own console they are
        // half of the only sign-in administration it has (the other half is single
        // sign-on for its team), so the two have to be one area to be found together.
        //
        // DOMAINS beside Enterprise SSO. A verified domain belongs to the organization, not
        // to one connection — it is how an email address finds whichever connection is
        // active — and it is one of the half-dozen things a customer's IT department owns
        // ({@see \App\Platform\Console\CustomerConsole}), so it has a page of its own.
        // The Enterprise SSO page still lists them beside its connections.
        $nav->area('authentication', 'Sign-in', 'fingerprint', 30)
            ->page('connections', Vocabulary::ENTERPRISE_SSO, order: 10)
            ->page('domains', Vocabulary::DOMAINS, order: 15)
            ->page('social-providers', Vocabulary::SOCIAL_LOGIN, order: 20)
            ->page('auth-policy', Vocabulary::AUTHENTICATION_POLICY, order: 25)
            ->page('directories', Vocabulary::DIRECTORY_SYNC, order: 30)
            ->page('provisioning', Vocabulary::OUTBOUND_PROVISIONING, order: 40);

        $nav->area('governance', 'Access control', 'scale', 40)
            ->page('governance', 'Access reviews', order: 10)
            ->page('sod-policies', 'Role conflicts', order: 20);

        $nav->area('developers', 'Developers', 'code', 50)
            // "Apps", not "Apps & API keys": the page registers apps, and keys have a page
            // of their own. The ampersand promised a second thing the page did not hold.
            ->page('clients', Vocabulary::APPLICATIONS, order: 10)
            // Publishable keys and Legacy login are on the environment plane only: both are
            // owned by the environment with no organization column, so listing them here
            // would put every organization's administrator in charge of every other
            // organization's. See ConsoleScope::assertMayAdministerEnvironment().
            ->page('webhooks', Vocabulary::WEBHOOKS, order: 20)
            ->page('hooks', Vocabulary::HOOKS, order: 30)
            ->page('vault', 'Token vault', order: 40);

        // 60 is left to the connectors module; the compliance and risk modules append
        // their pages to this area rather than minting their own (see below).
        //
        // AUDIT LOG, not "Logs": everything in it is the trail — the trail itself, the
        // events an app records about its customers, and where the trail is streamed. "Logs"
        // read as somewhere to find application output.
        $nav->area('audit', ConsoleArea::Logs->organizationLabel(), 'audit', 70)
            ->page('audit', Vocabulary::AUDIT_LOG, order: 10)
            // The audit events the app built on this environment sends about this
            // organization — the customer's view of its own product's activity.
            ->page('audit-logs', Vocabulary::APP_AUDIT_LOGS, order: 15)
            // Where this console is the environment's own administration — a single-tenant
            // install, the platform root. Not on a customer's console: there shipping the
            // trail to a SIEM is the vendor's job, done from the environment console.
            ->page('audit-streams', Vocabulary::LOG_STREAMS, order: 20);

        $nav->area('settings', 'Settings', 'settings', 80)
            ->page('settings', 'Settings', order: 10)
            ->page('appearance', 'Appearance', order: 20);

        // Every user's own security — shown to members and admins alike (the app
        // layout gates the admin-only areas above by role, this one is universal).
        $nav->area('account', 'My account', 'user', 90)
            ->page('account', 'Security', order: 10)
            // Beside it, because "change my password" and "sign that laptop out" are the
            // two halves of the same worry and people arrive looking for either.
            ->page('account.activity', 'Sessions & activity', order: 20)
            // Keys for the APIs of the apps built on this environment — present only where
            // one offers them, or the person already holds a key (see HolderApiKeys). "My API
            // keys" because the workspace's own page, two areas up, is "API keys".
            ->page('account.api-keys', Vocabulary::MY_API_KEYS, feature: 'account.api-keys', order: 30)
            // The third-party accounts (GitHub, Google…) the person connected so an app here
            // may act through them — present only where the environment offers a pipe or the
            // person still holds a connection.
            ->page('account.pipes', Vocabulary::CONNECTED_SERVICES, feature: 'account.pipes', order: 40);

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
        // WORKSPACES, not "Customers": the word the workspace's own console uses for itself,
        // so an operator and the person on the phone to them say the same word.
        $nav->area('platform', 'Platform', 'rocket', 100)
            ->page('platform.workspaces', 'Workspaces', feature: 'platform.operator', order: 10)
            ->page('platform.environments', 'Environments', feature: 'platform.operator', order: 20)
            ->page('platform.organizations', Vocabulary::ORGANIZATIONS, feature: 'platform.operator', order: 30);

        $nav->area('platform-insights', 'Insights', 'chart', 110)
            ->page('platform.usage', 'Usage', feature: 'platform.operator', order: 10)
            ->page('platform.search', 'Search', feature: 'platform.operator', order: 20)
            // Whether the background work — webhooks, sign-out notices, queued mail — is
            // being done. An install with no queue worker looks healthy everywhere else.
            ->page('platform.queues', 'Queues', feature: 'platform.operator', order: 30);

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
            ->page('platform.operators', Vocabulary::OPERATORS, feature: 'platform.operator', order: 10);
    }

    /**
     * The gates on the Workspace area, each one an ACCOUNT capability.
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
        // Usage is organization-wide telemetry — sign-ins, users created, tokens issued —
        // not the visitor's own record, so it is an administration surface sitting in an
        // area a member can see. THE SAME QUESTION THE PAGE ASKS, deliberately: the page
        // is the authorization and this only decides whether the rail offers a link to it.
        $features->register('organization.usage', static fn (): bool => app(ConsoleScope::class)->mayAdminister());
        // The two API key pages. The rail only decides whether to offer a link — each page
        // authorizes its own requests — so these ask about presence, in one statement per
        // request between them ({@see ApiKeyPresence}), on the organization the person is
        // in. The organization console's page only: an environment administrator (no
        // signed-in subject) reads the same list on each organization's own page.
        $features->register('account.api-keys', static function (): bool {
            $me = app(CurrentUser::class);
            $organizationId = $me->check() ? $me->organizationId() : null;

            return $organizationId !== null
                && app(ApiKeyPresence::class)->for($organizationId, $me->id())->worthHolderPage();
        });
        // Connected services: offered when the environment has an enabled pipe, or the
        // person still holds a connection they may want to remove.
        $features->register('account.pipes', static function (): bool {
            $me = app(CurrentUser::class);

            return $me->check() && (
                Pipe::query()->where('enabled', true)->exists()
                || PipeConnection::query()->where('user_id', $me->id())->exists()
            );
        });
        $features->register('organization.api-keys', static function (): bool {
            $me = app(CurrentUser::class);
            $organizationId = $me->check() ? $me->organizationId() : null;

            return $organizationId !== null
                && app(ConsoleScope::class)->mayAdminister()
                && app(ApiKeyPresence::class)->for($organizationId, $me->id())->worthAdminPage();
        });
    }
}
