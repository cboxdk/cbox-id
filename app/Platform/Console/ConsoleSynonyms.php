<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Navigation\ConsoleNavigation;

/**
 * THE WORDS PEOPLE TYPE INTO ⌘K, and the page each one means.
 *
 * The palette's "Go to" group matched a page by its area and its label and nothing else. The
 * labels are right — {@see Vocabulary} is the console's one word per thing — but a person
 * arriving from another identity platform, or from a ticket, types the word THEY have:
 * "SAML", "SCIM", "Google login", "tenant", "RBAC", "passkeys". Every one of those found
 * nothing, and "SAML" was worse than nothing: it matched "SAML apps", the OUTBOUND direction,
 * so the reader setting up a customer's Okta was offered the one page that is not it.
 *
 * So each page carries the words that mean it. They are not shown — the label stays the
 * label — they are matched: cmdk ranks a page by its keywords as well as its text
 * (`resources/js/chrome/CommandPalette.tsx`). One list for both rails, keyed by the page's
 * route WITHOUT its plane prefix, so `connections` answers for the organization console's
 * Enterprise SSO and `environment.connections` for the environment console's.
 *
 * WHAT GOES IN: the market's other words for the same thing (Clerk's, WorkOS's, Auth0's,
 * Okta's), the protocol and vendor names a reader searches by, and the plain-English task
 * ("invite", "redirect URI"). WHAT STAYS OUT: a word that names a DIFFERENT page better —
 * "SAML" is on Enterprise SSO, and only "SAML IdP"/"outbound SAML" on SAML apps, because
 * the inbound direction is the one nine readers in ten mean.
 *
 * `tests/Feature/ConsoleSynonymsTest.php` pins the searches a new administrator makes to
 * the page they should land on, and that every key here names a page one of the rails has
 * ({@see ConsoleNavigation}, the console-kit registry).
 */
final class ConsoleSynonyms
{
    /**
     * Page key (route name without `environment.`) => the words that find it.
     *
     * @var array<string, list<string>>
     */
    private const array WORDS = [
        // Home.
        'home' => ['dashboard', 'overview', 'start'],
        'get-started' => ['onboarding', 'setup', 'checklist', 'quickstart'],

        // Users & orgs.
        'users' => ['people', 'end users', 'identities', 'accounts', 'customers', 'reset password', 'reset MFA', 'sessions'],
        'organizations' => ['tenants', 'tenant', 'customers', 'companies', 'teams', 'accounts', 'B2B', 'workspaces'],
        'directory.members' => ['people', 'users', 'invite', 'team', 'membership'],
        'domains' => ['domain verification', 'verify domain', 'DNS', 'TXT record', 'email domain', 'capture', 'home realm discovery'],

        // Authentication.
        'sign-in-methods' => ['login methods', 'authentication methods', 'password', 'passkeys', 'WebAuthn', 'magic link', 'email code', 'OTP', 'passwordless', 'MFA', '2FA', 'two-factor', 'SMS', 'sessions', 'session lifetime'],
        'auth-policy' => ['password policy', 'password rules', 'MFA', '2FA', 'two-factor', 'multi-factor', 'lockout', 'brute force', 'sign-up', 'signup', 'registration', 'SMS', 'text message', 'require SSO', 'breached passwords', 'session timeout', 'idle timeout', 'session length', 'turn off passkeys', 'turn off magic link', 'bot challenge'],
        'social-providers' => ['social sign-in', 'social connections', 'OAuth', 'Google login', 'Sign in with Google', 'GitHub login', 'Microsoft login', 'Apple login', 'Sign in with Apple', 'Facebook', 'LinkedIn', 'Discord', 'Slack login', 'GitLab'],
        'connections' => ['SSO', 'SAML', 'OIDC', 'OpenID Connect', 'single sign-on', 'SAML connection', 'Okta', 'Entra ID', 'Azure AD', 'ADFS', 'Google Workspace SSO', 'Ping', 'OneLogin', 'IdP', 'identity provider', 'enterprise connection'],
        'directories' => ['SCIM', 'user provisioning', 'directory', 'HRIS', 'HR system', 'Workday', 'BambooHR', 'Rippling', 'HiBob', 'Personio', 'Google Workspace directory', 'Entra directory', 'groups', 'deprovisioning', 'sync users'],
        'radar' => ['fraud', 'bot protection', 'bots', 'risk', 'brute force', 'credential stuffing', 'impossible travel', 'challenge', 'blocked sign-in', 'allow list', 'deny list', 'IP block', 'Turnstile', 'CAPTCHA'],
        'devices.index' => ['devices', 'device trust', 'remembered devices'],

        // Authorization.
        'roles' => ['RBAC', 'role-based access control', 'access control', 'custom roles', 'assign role', 'change role'],
        'permissions' => ['RBAC', 'access control', 'scopes', 'abilities', 'privileges'],
        'fga' => ['FGA', 'ReBAC', 'relationship-based access control', 'Zanzibar', 'OpenFGA', 'authorization model', 'tuples', 'check', 'resource permissions', 'document permissions'],
        'governance' => ['access certification', 'user access review', 'UAR', 'recertification', 'SOC 2', 'compliance'],
        'sod-policies' => ['segregation of duties', 'separation of duties', 'SoD', 'toxic combinations', 'conflicting roles'],
        'staff' => ['support staff', 'internal admins', 'employees', 'super admin', 'staff roles'],

        // Developers.
        'clients' => ['apps', 'OAuth apps', 'OIDC clients', 'clients', 'redirect URI', 'callback URL', 'client ID', 'client secret', 'credentials', 'integrate', 'SDK'],
        'apis' => ['resource servers', 'audiences', 'scopes', 'API scopes', 'access tokens'],
        'keys.frontend' => ['publishable key', 'secret key', 'management key', 'API key', 'frontend key', 'pk_live', 'tokens'],
        'keys' => ['API key', 'workspace key', 'environment key', 'management key', 'tokens'],
        'webhooks' => ['events', 'event subscriptions', 'notifications', 'callbacks'],
        'hooks' => ['inline hooks', 'actions', 'token hook', 'pre-sign-in hook', 'custom claims', 'extensibility'],
        'feature-flags' => ['flags', 'toggles', 'feature toggles', 'rollout', 'beta', 'kill switch'],
        'pipes' => ['connected accounts', 'third-party tokens', 'OAuth tokens', 'GitHub API', 'Google API', 'token exchange'],

        // AI agents.
        'agents' => ['MCP', 'AI', 'management keys', 'service accounts', 'bots', 'LLM'],
        'approvals' => ['CIBA', 'human in the loop', 'pending', 'consent', 'step-up'],
        'agent-connect' => ['MCP', 'MCP server', 'Claude', 'Claude Code', 'Cursor', 'VS Code', 'connect an agent', 'AI agent'],

        // Branding.
        'branding' => ['appearance', 'theme', 'logo', 'favicon', 'colours', 'colors', 'font', 'typeface', 'login page', 'sign-in page', 'hosted login', 'universal login', 'customize', 'white label', 'white-label', 'app name', 'email sender', 'email template', 'custom branding'],

        // Monitoring.
        'audit' => ['audit trail', 'activity', 'history', 'events', 'logs', 'who did what'],
        'audit-logs' => ['customer audit logs', 'app events', 'audit log API', 'event schemas', 'export'],
        'audit-streams' => ['SIEM', 'Splunk', 'Datadog', 'log export', 'log forwarding', 'streaming'],
        'usage' => ['metrics', 'MAU', 'monthly active users', 'statistics', 'analytics', 'billing usage'],
        'sign-in-activity' => ['analytics', 'logins', 'sign-in stats'],
        'risk-plus.events' => ['risk', 'suspicious sign-ins', 'anomalies'],

        // Advanced.
        'vault' => ['secrets', 'stored tokens', 'credentials vault'],
        'provisioning' => ['SCIM out', 'outbound SCIM', 'push users', 'provision to apps'],
        'sso-providers' => ['SAML IdP', 'outbound SAML', 'service providers', 'SAML service provider', 'IdP-initiated'],
        'legacy-login' => ['migration', 'migrate users', 'import passwords', 'password import', 'Auth0 migration', 'trickle migration'],

        // Settings.
        'settings' => ['issuer', 'discovery', 'OIDC endpoints', 'well-known', 'environment ID', 'JWKS'],

        // The organization console's and the workspace's own pages.
        'dashboard' => ['home', 'overview'],
        'account' => ['profile', 'my password', 'my passkeys', 'my MFA', 'security'],
        'account.activity' => ['my sessions', 'sign out everywhere', 'devices'],
        'projects' => ['environments', 'production', 'sandbox', 'new environment'],
        'members' => ['team', 'teammates', 'invite', 'colleagues'],
        'environment-domains' => ['custom domain', 'CNAME', 'vanity domain', 'auth domain'],
        'organization-settings' => ['workspace name', 'delete workspace'],
    ];

    /**
     * The words that find the page at $route, on either plane.
     *
     * @return list<string>
     */
    public static function for(string $route): array
    {
        $key = str_starts_with($route, 'environment.') ? substr($route, strlen('environment.')) : $route;

        return self::WORDS[$key] ?? [];
    }

    /**
     * Every key, for the test that holds each one to a page that exists.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::WORDS);
    }
}
