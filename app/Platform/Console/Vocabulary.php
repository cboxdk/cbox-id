<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Navigation\ConsoleNavigation;
use App\Providers\ConsoleServiceProvider;

/**
 * THE CONSOLE'S NOUNS — one word per thing, written down once.
 *
 * The console grew its words page by page, and one thing collected several: the audit
 * trail was "Activity log" on one page and "Audit log" on the next; inbound SCIM was
 * "Sync users in" in the rail and "Directory Sync" in the page header; a request from an
 * agent to act as somebody was "Agent approvals", "Approve agent requests" and "Review agent
 * requests" depending on the console; inbound federation was "Single sign-on" here and
 * "Enterprise SSO" there. A person who learned the word on one page searched for it on the
 * next and did not find it.
 *
 * So the nouns live here, and the places that name a console area read them: the rails
 * ({@see ConsoleServiceProvider}, {@see ConsoleNavigation}, {@see ConsoleArea}), the page
 * titles behind those rails, and the key page's tabs ({@see KeyTabs}). A rename is a
 * change to this file and whatever test pins the old word — not a hunt.
 *
 * {@see self::RETIRED} is the other half: the words that used to name these things, each
 * with the word to use instead. `tests/Feature/ConsoleVocabularyTest.php` reads it and
 * fails the build when one of them comes back into copy a person reads.
 *
 * THE WORDS, and what each one means — the distinctions are the point:
 *
 *  - Workspace: the customer's own Cbox account. Never "account" or "customer" in copy.
 *  - Team: the colleagues who administer a workspace.
 *  - Users: the end-users of an environment — everyone who can sign in to the product.
 *  - Members: the people in one organization.
 *  - Admins & support: an environment's own staff, holding roles across every
 *    organization in it.
 *  - Operators: the people who run this install — Cbox's own, on the hosted platform.
 *  - Enterprise SSO: people arriving with a company account (inbound SAML/OIDC).
 *    SAML apps is the opposite direction: applications that trust this environment.
 *  - Directory Sync: a directory pushing people IN over SCIM. Outbound provisioning
 *    pushes them OUT.
 *  - Audit log: this platform's own hash-chained trail. App audit logs are the events an
 *    app built on an environment sends about its own customers.
 *  - Approvals: what an agent is waiting for a person to allow.
 *  - API keys: one page, the kind of key a tab — Secret, Publishable, Workspace.
 *  - Radar: adaptive protection at sign-in and sign-up — the rules that allow, challenge or
 *    block an attempt, and the decisions they made. Not "Risk events": that is the
 *    risk-plus module's feed of elevated scores, a narrower thing.
 *  - Feature flags: switches an app asks about per user and organization. Not
 *    "entitlements", which are what a customer has paid for and are set from billing.
 *  - Fine-grained authorization: the relationship model an app defines for its OWN
 *    resources (documents in folders) — beside Roles and Permissions, which are what a
 *    person may do in an organization.
 */
final class Vocabulary
{
    // People.
    public const string WORKSPACE = 'Workspace';

    public const string TEAM = 'Team';

    public const string USERS = 'Users';

    public const string MEMBERS = 'Members';

    /** The organization console's area holding Members and what they hold. */
    public const string MEMBERS_AND_ROLES = 'Members & roles';

    public const string ORGANIZATIONS = 'Organizations';

    public const string ADMINS_AND_SUPPORT = 'Admins & support';

    /** One role on the Admins & support page — held across every organization. */
    public const string ADMIN_AND_SUPPORT_ROLE = 'Admin & support role';

    public const string ADMIN_AND_SUPPORT_ROLES = 'Admin & support roles';

    public const string OPERATORS = 'Operators';

    public const string ROLES = 'Roles';

    public const string PERMISSIONS = 'Permissions';

    /** An environment's own relationship model: schema, tuples, checks. Not roles or permissions. */
    public const string FINE_GRAINED_AUTHORIZATION = 'Fine-grained authorization';

    // Sign-in.
    public const string ENTERPRISE_SSO = 'Enterprise SSO';

    public const string DOMAINS = 'Domains';

    public const string DIRECTORY_SYNC = 'Directory Sync';

    public const string OUTBOUND_PROVISIONING = 'Outbound provisioning';

    public const string AUTHENTICATION_POLICY = 'Authentication policy';

    public const string SOCIAL_LOGIN = 'Social login';

    public const string SAML_APPS = 'SAML apps';

    /** Adaptive protection at sign-in and sign-up: rules, lists, and the decisions it made. */
    public const string RADAR = 'Radar';

    // Building on an environment.
    public const string APPLICATIONS = 'Applications';

    public const string WEBHOOKS = 'Webhooks';

    public const string HOOKS = 'Hooks';

    /** Switches an app asks about per user and organization (Developers › Feature flags). */
    public const string FEATURE_FLAGS = 'Feature flags';

    /**
     * A third-party provider people connect their OWN account at (GitHub, Google, Slack…)
     * so an app here can call that API as them. Not a sign-in method: Social login is how
     * people get IN; a pipe is how an app reaches OUT on their behalf.
     */
    public const string PIPES = 'Pipes';

    /** What a person calls their pipe connections on My account. */
    public const string CONNECTED_SERVICES = 'Connected services';

    public const string API_KEYS = 'API keys';

    /** The person's own keys, on My account — not the workspace's API keys page. */
    public const string MY_API_KEYS = 'My API keys';

    /** The keys an organization's members hold for its apps, as its administrators see them. */
    public const string MEMBER_API_KEYS = 'Member API keys';

    /** A management key — server-side, shown once, calling one environment's API. */
    public const string SECRET_KEYS = 'Secret keys';

    /** A frontend key — public by design, bounded by the origins it may be used from. */
    public const string PUBLISHABLE_KEYS = 'Publishable keys';

    public const string WORKSPACE_KEYS = 'Workspace keys';

    // Agents.
    public const string AGENTS = 'Agents';

    public const string APPROVALS = 'Approvals';

    // The record.
    public const string AUDIT_LOG = 'Audit log';

    public const string APP_AUDIT_LOGS = 'App audit logs';

    public const string LOG_STREAMS = 'Log streams';

    /**
     * THE RETIRED WORDS — a pattern matched against copy a person reads, and what to say
     * instead.
     *
     * Labels that were ONLY ever labels ("Staff", "Single sign-on" as a heading) are
     * anchored to the whole string, so a sentence explaining single sign-on, or a staff
     * member doing something, is not a match. Phrases that are wrong wherever they appear
     * are not anchored.
     *
     * @var array<string, string>
     */
    public const array RETIRED = [
        '/\bactivity log\b/i' => self::AUDIT_LOG.' (the trail\'s internal name stays in code, not in copy)',
        '/\bSync users (in|out)\b/i' => self::DIRECTORY_SYNC.' (in) or '.self::OUTBOUND_PROVISIONING.' (out)',
        '/^Single sign-on$/i' => self::ENTERPRISE_SSO.' — as a label; a sentence may still explain single sign-on',
        '/\bagent requests?\b/i' => self::APPROVALS.', or "approval request" in a sentence',
        '/^Staff\b/' => self::ADMINS_AND_SUPPORT.' (the page) or '.self::ADMIN_AND_SUPPORT_ROLE.' (one of its roles)',
        '/\bLog streaming\b/i' => self::LOG_STREAMS,
        '/\bSign-in rules\b/i' => self::AUTHENTICATION_POLICY,
        '/\bSocial sign-in\b/i' => self::SOCIAL_LOGIN,
        '/\binline hooks?\b/i' => self::HOOKS.', or "hook" in a sentence',
        '/\bSAML applications?\b/i' => self::SAML_APPS,
    ];
}
