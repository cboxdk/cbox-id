<?php

declare(strict_types=1);

namespace App\Platform\Scopes;

/**
 * EVERY SCOPE ID ENFORCES, BY ITS NEW NAME AND ITS OLD ONE.
 *
 * The standard names an API scope `<product>:<resource>:<read|write>` — `id:users:read`,
 * `id:organization:write`. ID grew four spellings before it had one (`workspace:read`,
 * `vault.manage`, `apps.manifest`, `signals:events:read`), and every app, agent and CLI that
 * holds a key or a token today carries them. So v3.0 speaks both:
 *
 *  - a credential holding the OLD name satisfies a route or action that asks for the NEW one,
 *    and the other way round ({@see self::satisfies()}) — nothing a caller holds stops working;
 *  - routes and actions declare the new names, and the API documents them;
 *  - v3.1 drops the old names once telemetry shows nobody presents one.
 *
 * Old names the standard collapses into one new name (`vault.manage` and
 * `token_vault:write` are both `id:vault-secrets:write`) do not satisfy EACH OTHER in v3.0:
 * a rename changes no grant. One old name that granted two things expands to both
 * (`keys:write` minted organization AND environment keys).
 *
 * `directories:read` / `directories:write` are the framework's reserved scopes, used by no
 * endpoint; they are not `directory_sync:*` and are left out until something uses them.
 *
 * Not here, on purpose: OIDC protocol scopes (`openid`, `profile`, `email`,
 * `offline_access`) and the scopes a customer defines on its own APIs — those belong to the
 * customer's product, not to ID.
 */
final class ScopeRegistry
{
    /** The shape every new name has: `id:<resource>:<read|write>`. */
    public const string PATTERN = '/^id:[a-z][a-z0-9-]*:(read|write)$/';

    /**
     * Old name => the new name(s) it stands for.
     *
     * @var array<string, list<string>>
     */
    public const array RENAMED = [
        'account:api_keys:write' => ['id:my-api-keys:write'],
        'account:applications:write' => ['id:my-apps:write'],
        'account:apps:read' => ['id:my-apps:read'],
        'account:devices:write' => ['id:my-devices:write'],
        'account:organizations:write' => ['id:my-organizations:write'],
        'account:pipes:write' => ['id:my-pipes:write'],
        'account:profile:write' => ['id:my-profile:write'],
        'account:sessions:write' => ['id:my-sessions:write'],
        'account:sign_in:write' => ['id:my-sign-in:write'],
        'account:waitlists:write' => ['id:my-waitlists:write'],
        'api_keys:read' => ['id:api-keys:read'],
        'api_keys:write' => ['id:api-keys:write'],
        'apis:read' => ['id:apis:read'],
        'apis:write' => ['id:apis:write'],
        'app_catalog:read' => ['id:app-catalog:read'],
        'app_catalog:resolve' => ['id:app-catalog-for-user:read'],
        'app_catalog:write' => ['id:app-catalog:write'],
        'approvals.read' => ['id:approvals:read'],
        'approvals.write' => ['id:approvals:write'],
        'approvals:read' => ['id:approvals:read'],
        'approvals:write' => ['id:approvals:write'],
        'apps.manifest' => ['id:app-manifests:write'],
        'apps:read' => ['id:apps:read'],
        'apps:write' => ['id:apps:write'],
        'audit:read' => ['id:audit-events:read'],
        'audit_logs:export' => ['id:audit-log-exports:write'],
        'audit_logs:manage' => ['id:audit-log-schemas:write'],
        'audit_logs:read' => ['id:audit-logs:read'],
        'audit_logs:write' => ['id:audit-logs:write'],
        'billing:write' => ['id:billing:write'],
        'branding:read' => ['id:branding:read'],
        'branding:write' => ['id:branding:write'],
        'devices.manage' => ['id:devices:write'],
        'directory_sync:read' => ['id:directories:read'],
        'directory_sync:write' => ['id:directories:write'],
        'domains:read' => ['id:domains:read'],
        'domains:write' => ['id:domains:write'],
        'environments:write' => ['id:environments:write'],
        'events:read' => ['id:events:read'],
        'feature_flags:read' => ['id:feature-flags:read'],
        'feature_flags:write' => ['id:feature-flags:write'],
        'fga:read' => ['id:fga:read'],
        'fga:schema' => ['id:fga-schema:write'],
        'fga:write' => ['id:fga:write'],
        'frontend_keys:read' => ['id:frontend-keys:read'],
        'frontend_keys:write' => ['id:frontend-keys:write'],
        'governance:read' => ['id:governance:read'],
        'governance:write' => ['id:governance:write'],
        'hooks:read' => ['id:hooks:read'],
        'hooks:write' => ['id:hooks:write'],
        'invitations:read' => ['id:invitations:read'],
        'invitations:write' => ['id:invitations:write'],
        'keys:read' => ['id:environment-keys:read'],
        'keys:write' => ['id:environment-keys:write', 'id:organization-keys:write'],
        'log_streams:read' => ['id:log-streams:read'],
        'log_streams:write' => ['id:log-streams:write'],
        'members:read' => ['id:organization-memberships:read'],
        'members:write' => ['id:organization-memberships:write'],
        'operator:environments:write' => ['id:admin-environments:write'],
        'operator:operators:write' => ['id:admin-operators:write'],
        'operator:organizations:write' => ['id:admin-tenant-organizations:write'],
        'operator:workspaces:write' => ['id:admin-organizations:write'],
        'organizations:read' => ['id:organizations:read'],
        'organizations:write' => ['id:organizations:write'],
        'pipes:read' => ['id:pipes:read'],
        'pipes:write' => ['id:pipes:write'],
        'portal_links:read' => ['id:portal-links:read'],
        'portal_links:write' => ['id:portal-links:write'],
        'projects:write' => ['id:projects:write'],
        'provisioning:read' => ['id:provisioning-targets:read'],
        'provisioning:write' => ['id:provisioning-targets:write'],
        'radar:manage' => ['id:radar-settings:write'],
        'radar:read' => ['id:radar:read'],
        'radar:write' => ['id:radar:write'],
        'role_definitions:write' => ['id:role-definitions:write'],
        'roles:read' => ['id:roles:read'],
        'roles:write' => ['id:roles:write'],
        'saml_apps:read' => ['id:saml-apps:read'],
        'saml_apps:write' => ['id:saml-apps:write'],
        'service_connections.inspect' => ['id:service-connections:read'],
        'service_connections.lease' => ['id:service-connection-leases:write'],
        'service_connections.manage' => ['id:service-connections:write'],
        'settings:write' => ['id:organization:write'],
        'signals:events:read' => ['id:signals-events:read'],
        'signals_source:read' => ['id:signals-source:read'],
        'signin:read' => ['id:sign-in:read'],
        'signin:write' => ['id:sign-in:write'],
        'sso:read' => ['id:sso-connections:read'],
        'sso:write' => ['id:sso-connections:write'],
        'support:write' => ['id:support-sessions:write'],
        'team:read' => ['id:members:read'],
        'team:write' => ['id:members:write'],
        'token_vault:read' => ['id:vault-secrets:read'],
        'token_vault:write' => ['id:vault-secrets:write'],
        'user_property_namespaces:read' => ['id:user-property-namespaces:read'],
        'user_property_namespaces:write' => ['id:user-property-namespaces:write'],
        'users:erase' => ['id:user-erasures:write'],
        'users:read' => ['id:users:read'],
        'users:write' => ['id:users:write'],
        'vault.lease' => ['id:vault-leases:write'],
        'vault.manage' => ['id:vault-secrets:write'],
        'webhooks:read' => ['id:webhooks:read'],
        'webhooks:write' => ['id:webhooks:write'],
        'workspace:read' => ['id:organization:read'],
    ];

    /** @var array<string, list<string>>|null new name => the old names that stand for it */
    private static ?array $legacy = null;

    /**
     * Every new name ID enforces.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::legacyMap());
    }

    /** Whether $scope is a name ID knows, new or old. */
    public static function knows(string $scope): bool
    {
        return isset(self::RENAMED[$scope]) || isset(self::legacyMap()[$scope]);
    }

    /** Whether $scope is an old name, kept for v3.0 only. */
    public static function isLegacy(string $scope): bool
    {
        return isset(self::RENAMED[$scope]);
    }

    /**
     * The new name(s) for $scope: itself when it is new or unknown, its replacements when old.
     *
     * @return list<string>
     */
    public static function current(string $scope): array
    {
        return self::RENAMED[$scope] ?? [$scope];
    }

    /**
     * The one new name for $scope, for a place that holds exactly one (a route, an action).
     * An old name that expands to two keeps its first.
     */
    public static function canonical(string $scope): string
    {
        return self::current($scope)[0];
    }

    /**
     * Every name that satisfies a requirement for $scope: the new name and each old one that
     * stands for it. Ask a credential "do you hold any of these?" and an old credential and a
     * new one get the same answer.
     *
     * @return list<string>
     */
    public static function equivalents(string $scope): array
    {
        // An old name is satisfied by itself and its new name — never by a SIBLING old name
        // that collapses into the same new one (`vault.manage` is not `token_vault:write`
        // today; merging what they grant is a decision of its own, not a rename). A new
        // name is satisfied by itself and every old name that stands for it. An old name that
        // SPLIT (`keys:write` → environment keys and organization keys) is satisfied only by
        // the new name of what it guards where it is still declared: its first, canonical
        // one. Otherwise a key holding only `id:organization-keys:write` would pass the
        // environment plane's `keys:write` check.
        $names = isset(self::RENAMED[$scope])
            ? [$scope, self::canonical($scope)]
            : [$scope, ...(self::legacyMap()[$scope] ?? [])];

        return array_values(array_unique($names));
    }

    /**
     * Whether a credential answering $holds (one name at a time) satisfies $required.
     *
     * @param  callable(string): bool  $holds
     */
    public static function satisfiedBy(string $required, callable $holds): bool
    {
        foreach (self::equivalents($required) as $name) {
            if ($holds($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a list of held scopes satisfies $required.
     *
     * @param  iterable<mixed>  $held
     */
    public static function satisfies(iterable $held, string $required): bool
    {
        $list = [];
        foreach ($held as $scope) {
            if (is_string($scope)) {
                $list[$scope] = true;
            }
        }

        return self::satisfiedBy($required, static fn (string $name): bool => isset($list[$name]));
    }

    /**
     * $scopes in their new names, without duplicates, in the order given.
     *
     * @param  iterable<mixed>  $scopes
     * @return list<string>
     */
    public static function normalise(iterable $scopes): array
    {
        $out = [];

        foreach ($scopes as $scope) {
            if (is_string($scope)) {
                foreach (self::current($scope) as $new) {
                    $out[$new] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * $scopes plus every old name that stands for one of them: what a client may still ASK
     * for while v3.0 accepts both.
     *
     * @param  iterable<string>  $scopes
     * @return list<string>
     */
    public static function withLegacy(iterable $scopes): array
    {
        $out = [];

        foreach ($scopes as $scope) {
            foreach (self::equivalents($scope) as $name) {
                $out[$name] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array<string, list<string>> */
    private static function legacyMap(): array
    {
        if (self::$legacy !== null) {
            return self::$legacy;
        }

        $map = [];
        foreach (self::RENAMED as $old => $new) {
            foreach ($new as $name) {
                $map[$name][] = $old;
            }
        }

        return self::$legacy = $map;
    }
}
