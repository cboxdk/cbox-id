<?php

declare(strict_types=1);

namespace App\Platform\Api\V1;

/**
 * WHERE EVERY `/api/v1` OPERATION LIVES ON THE API HOST (plan §2.3).
 *
 * The rule (standard §4): `/v1/<resource>[/{id}[/<sub-resource>|/<verb>]]`, after the `me` or
 * `admin` plane prefix. At most one path parameter, always in the `{id}` position; PATCH for
 * an update; PUT only for a whole replacement ({@see self::WHOLE_REPLACEMENTS}); one resource
 * per concept. A child addressed under its parent today (`/organizations/{o}/members/{u}`)
 * is addressed directly, and the parent it belongs to is named in the query or the body.
 *
 * {@see self::target()} answers for one operation: first {@see self::MOVED} (the operations
 * whose shape changes), then {@see self::RENAMED} (a resource that only changes its name),
 * then the path as it is. `tests/Feature/Api/RouteShapeTest.php` holds the result to the
 * rule over the whole route table.
 */
final class V1Paths
{
    /**
     * Operations that move or change shape, keyed `METHOD /old/path` (relative to `/api/v1`,
     * plane mount included). The value is the new method and path — its one `{…}` the old
     * parameter the new `{id}` stands for — and the old parameters that now come from the
     * request's input, `old => public name`. Null: not on `/v1` at all.
     *
     * @var array<string, array{0: string, 1: string, 2?: array<string, string>, 3?: string}|null>
     */
    public const array MOVED = [
        // ── The organization plane: the organization is the credential's, so no prefix ──
        'GET /workspace' => ['GET', '/organization'],
        'PATCH /workspace' => ['PATCH', '/organization'],
        'POST /workspace/projects/verification/resend' => ['POST', '/organization/resend-verification'],
        'POST /workspace/environments/{environment_id}/domain' => ['POST', '/environments/{environment_id}/domain'],
        'DELETE /workspace/environments/{environment_id}/domain' => ['DELETE', '/environments/{environment_id}/domain'],
        'POST /workspace/environments/{environment_id}/domain/verify' => ['POST', '/environments/{environment_id}/verify-domain'],
        'POST /workspace/environments/{environment_id}/keys' => ['POST', '/environments/{environment_id}/keys'],
        'DELETE /workspace/environments/{environment_id}/keys/{id}' => ['DELETE', '/environment-keys/{id}', ['environment_id' => 'environment_id']],
        // One update for a member: the role and the environments they reach are fields of it.
        'PATCH /workspace/members/{id}/role' => ['PATCH', '/members/{id}', [], 'roles'],
        'PUT /workspace/members/{id}/access' => ['PATCH', '/members/{id}', [], 'all_environments'],
        'PATCH /workspace/billing/account' => ['PATCH', '/billing-account'],
        'DELETE /workspace/billing/payment-methods/{id}' => ['DELETE', '/payment-methods/{id}'],
        'PATCH /workspace/billing/payment-methods/{id}/default' => ['POST', '/payment-methods/{id}/make-default'],
        'GET /workspace/action-approvals/{id}' => ['GET', '/approvals/{id}'],
        'GET /workspace/openapi.yaml' => null,

        // ── The admin plane: one organization resource, told apart by its fields ──
        'POST /platform/workspaces' => ['POST', '/admin/organizations', [], '!environment_id'],
        'PUT /platform/workspaces/{workspace_id}/status' => ['PATCH', '/admin/organizations/{workspace_id}', [], '!environment_id'],
        'POST /platform/environments/{environment_id}/organizations' => ['POST', '/admin/organizations', ['environment_id' => 'environment_id'], 'environment_id'],
        'PUT /platform/environments/{environment_id}/organizations/{organization_id}/parent' => ['PATCH', '/admin/organizations/{organization_id}', ['environment_id' => 'environment_id'], 'environment_id+parent_id'],
        'PUT /platform/environments/{environment_id}/organizations/{organization_id}/status' => ['PATCH', '/admin/organizations/{organization_id}', ['environment_id' => 'environment_id'], 'environment_id+status'],
        'PUT /platform/operators/{operator_id}/status' => ['PATCH', '/admin/operators/{operator_id}'],
        'GET /platform/action-approvals/{id}' => ['GET', '/admin/approvals/{id}'],
        'GET /platform/openapi.yaml' => null,

        // ── The account plane ──
        'GET /me/action-approvals/{id}' => ['GET', '/me/approvals/{id}'],
        'POST /me/organizations/{organization_id}/api-keys' => ['POST', '/me/api-keys', ['organization_id' => 'organization_id']],
        'DELETE /me/organizations/{organization_id}/api-keys/{key_id}' => ['DELETE', '/me/api-keys/{key_id}', ['organization_id' => 'organization_id']],
        'POST /me/services/{program}/waitlist' => ['POST', '/me/services/{program}/join-waitlist'],
        'POST /me/services/{program}/waitlist/leave' => ['POST', '/me/services/{program}/leave-waitlist'],
        'GET /me/openapi.yaml' => null,

        // ── The Authenticator: the approvals a person decides, and their devices ──
        'GET /approvals' => ['GET', '/me/approvals'],
        'GET /approvals/{id}' => ['GET', '/me/approvals/{id}'],
        'POST /approvals/{id}/approve' => ['POST', '/me/approvals/{id}/approve'],
        'POST /approvals/{id}/deny' => ['POST', '/me/approvals/{id}/deny'],
        'GET /authenticator/me' => ['GET', '/me/authenticator'],

        // ── The environment plane ──
        'GET /environment/openapi.yaml' => null,
        'POST /onboarding/eligibility' => null,
        'GET /agent-requests' => ['GET', '/approvals'],
        'POST /agent-requests/{request_id}/deny' => ['POST', '/approvals/{request_id}/deny'],
        'GET /action-approvals/{id}' => ['GET', '/approvals/{id}'],
        'POST /access-reviews/{id}/items/{item_id}' => ['POST', '/access-review-items/{item_id}/decide', ['id' => 'access_review_id']],
        'GET /organizations/{organization_id}/api-keys' => ['GET', '/api-keys', ['organization_id' => 'organization_id']],
        'PUT /apis/{id}/scopes/{key}' => ['PUT', '/api-scopes/{key}', ['id' => 'api_id']],
        'DELETE /apis/{id}/scopes/{key}' => ['DELETE', '/api-scopes/{key}', ['id' => 'api_id']],
        'POST /apps/manifest' => ['POST', '/app-manifests'],
        'POST /apps/{id}/manifest/sync' => ['POST', '/apps/{id}/sync-manifest'],
        'DELETE /apps/{id}/secrets/{secret_id}' => ['DELETE', '/app-secrets/{secret_id}', ['id' => 'app_id']],
        'PUT /apps/{id}/settings/api-key-prefix' => ['PUT', '/apps/{id}/api-key-prefix'],
        'PUT /apps/{id}/settings/backchannel-logout' => ['PUT', '/apps/{id}/backchannel-logout'],
        'PUT /apps/{id}/settings/client-credentials' => ['PUT', '/apps/{id}/client-credentials'],
        'PUT /apps/{id}/settings/token-exchange' => ['PUT', '/apps/{id}/token-exchange'],
        'PUT /apps/{id}/settings/token-lifetime' => ['PUT', '/apps/{id}/token-lifetime'],
        'GET /audit-log' => ['GET', '/audit-events'],
        'GET /audit-logs/verify' => ['GET', '/audit-log-events/verify'],
        'POST /directories/hris' => ['POST', '/directories/connect-hris'],
        'POST /domains/verify' => ['POST', '/environment-domain/verify'],
        'GET /fga/check/batch' => ['GET', '/fga/check-batch'],
        'GET /fga/schema/validate' => ['GET', '/fga/validate-schema'],
        'POST /fga/tuples/delete' => ['POST', '/fga/delete-tuples'],
        // An organization's domains are one resource: the SSO domain actions, which know a
        // domain by its own id. The organization-scoped copies are not repeated on /v1.
        'POST /organizations/{organization_id}/domains' => null,
        'GET /organizations/{organization_id}/domains' => null,
        'DELETE /organizations/{organization_id}/domains/{domain_id}' => null,
        'PUT /organizations/{organization_id}/domains/{domain_id}/capture' => null,
        'POST /organizations/{organization_id}/domains/{domain_id}/verify' => null,
        'POST /sso/domains/{id}/capture' => ['PATCH', '/organization-domains/{id}'],
        'GET /organizations/{organization_id}/invitations' => ['GET', '/organization-invitations', ['organization_id' => 'organization_id']],
        'POST /organizations/{organization_id}/invitations' => ['POST', '/organization-invitations', ['organization_id' => 'organization_id']],
        'GET /organizations/{organization_id}/invitations/{invitation_id}' => ['GET', '/organization-invitations/{invitation_id}', ['organization_id' => 'organization_id']],
        'DELETE /organizations/{organization_id}/invitations/{invitation_id}' => ['DELETE', '/organization-invitations/{invitation_id}', ['organization_id' => 'organization_id']],
        'POST /organizations/{organization_id}/invitations/{invitation_id}/resend' => ['POST', '/organization-invitations/{invitation_id}/resend', ['organization_id' => 'organization_id']],
        'GET /organizations/{organization_id}/members' => ['GET', '/organization-memberships', ['organization_id' => 'organization_id']],
        'POST /organizations/{organization_id}/members' => ['POST', '/organization-memberships', ['organization_id' => 'organization_id']],
        'PATCH /organizations/{organization_id}/members/{user_id}' => ['PATCH', '/organization-memberships/{user_id}', ['organization_id' => 'organization_id']],
        'DELETE /organizations/{organization_id}/members/{user_id}' => ['DELETE', '/organization-memberships/{user_id}', ['organization_id' => 'organization_id']],
        'PUT /organizations/{organization_id}/members/{user_id}/apps/{client_id}/roles' => ['PUT', '/organization-memberships/{user_id}/app-roles', ['organization_id' => 'organization_id', 'client_id' => 'client_id']],
        'GET /organizations/{organization_id}/members/{user_id}/roles' => ['GET', '/role-assignments', ['organization_id' => 'organization_id', 'user_id' => 'user_id']],
        'PUT /organizations/{organization_id}/members/{user_id}/roles/{role_id}' => ['PUT', '/role-assignments/{role_id}', ['organization_id' => 'organization_id', 'user_id' => 'user_id']],
        'DELETE /organizations/{organization_id}/members/{user_id}/roles/{role_id}' => ['DELETE', '/role-assignments/{role_id}', ['organization_id' => 'organization_id', 'user_id' => 'user_id']],
        'GET /organizations/{organization_id}/portal-links' => ['GET', '/portal-links', ['organization_id' => 'organization_id']],
        'POST /organizations/{organization_id}/portal-links' => ['POST', '/portal-links', ['organization_id' => 'organization_id']],
        'DELETE /organizations/{organization_id}/portal-links/{id}' => ['DELETE', '/portal-links/{id}', ['organization_id' => 'organization_id']],
        'DELETE /pipes/{id}/connections/{connection_id}' => ['DELETE', '/pipe-connections/{connection_id}', ['id' => 'pipe_id']],
        'DELETE /pipes/{id}/grants/{client_id}' => ['DELETE', '/pipe-grants/{client_id}', ['id' => 'pipe_id']],
        'PUT /radar/mode' => ['POST', '/radar-settings/set-mode'],
        'PUT /radar/rules/order' => ['POST', '/radar-rules/reorder'],
        'POST /roles/{id}/definition/activate' => ['POST', '/roles/{id}/activate-definition'],
        'PUT /roles/{id}/permissions/{permission_id}' => ['PUT', '/role-permissions/{permission_id}', ['id' => 'role_id']],
        'DELETE /roles/{id}/permissions/{permission_id}' => ['DELETE', '/role-permissions/{permission_id}', ['id' => 'role_id']],
        'POST /service-connections/{id}' => ['PUT', '/service-connections/{id}'],
        // `/status` folds into `/inspect`, which takes either scope (see V1Routes).
        'POST /service-connections/{id}/status' => null,
        'POST /service-connections/{id}/token' => ['POST', '/service-connections/{id}/lease'],
        'DELETE /sign-in/policy/organizations/{organization_id}' => ['POST', '/organizations/{organization_id}/inherit-sign-in-policy'],
        'PUT /sign-in/social-providers/inherited/{provider}' => ['POST', '/social-connections/inherit', ['provider' => 'provider']],
        'POST /sso/connections/{id}/certificates/activate' => ['POST', '/sso-connections/{id}/activate-certificate'],
        'POST /sso/saml-metadata' => ['POST', '/saml-metadata-imports'],
        'POST /token-vault/secrets/{id}/revoke' => ['DELETE', '/vault-secrets/{id}'],
        'DELETE /token-vault/secrets/{id}/grants/{client_id}' => ['DELETE', '/vault-secret-grants/{client_id}', ['id' => 'secret_id']],
        'GET /users/{id}/environment-roles/{role_id}' => ['GET', '/environment-role-assignments/{role_id}', ['id' => 'user_id']],
        'PUT /users/{id}/environment-roles/{role_id}' => ['PUT', '/environment-role-assignments/{role_id}', ['id' => 'user_id']],
        'DELETE /users/{id}/environment-roles/{role_id}' => ['DELETE', '/environment-role-assignments/{role_id}', ['id' => 'user_id']],
        'DELETE /users/{id}/mfa/sms' => ['DELETE', '/users/{id}/sms-factor'],
        'DELETE /users/{id}/sessions/{session_id}' => ['DELETE', '/user-sessions/{session_id}', ['id' => 'user_id']],
        // One vault-secret resource: the action-backed handler. The older key-only copies
        // are not repeated; their lease is (V1Routes), and the pipe lease keys on the body.
        'POST /vault/secrets' => null,
        'DELETE /vault/secrets/{id}' => null,
        'POST /vault/secrets/{id}/rotate' => null,
        'POST /vault/secrets/{id}/grants' => null,
        'DELETE /vault/secrets/{id}/grants/{clientId}' => null,
        'POST /vault/secrets/{id}/lease' => ['POST', '/vault-secrets/{id}/lease'],
        'POST /vault/pipes/{provider}/token' => ['POST', '/pipe-leases', ['provider' => 'provider']],
    ];

    /**
     * A resource that only changes its name: an old path prefix and the new one, matched on
     * a segment boundary, first match wins.
     *
     * @var array<string, string>
     */
    public const array RENAMED = [
        '/workspace/projects' => '/projects',
        '/workspace/environments' => '/environments',
        '/workspace/members' => '/members',
        '/workspace/invitations' => '/invitations',
        '/workspace/keys' => '/organization-keys',
        '/platform/environments' => '/admin/environments',
        '/platform/operators' => '/admin/operators',
        '/me/two-factor/apps' => '/me/totp-factors',
        '/sso/connections' => '/sso-connections',
        '/sso/domains' => '/organization-domains',
        '/radar/rules' => '/radar-rules',
        '/radar/lists' => '/radar-lists',
        '/radar/decisions' => '/radar-decisions',
        '/radar/settings' => '/radar-settings',
        '/audit-logs/events' => '/audit-log-events',
        '/audit-logs/exports' => '/audit-log-exports',
        '/audit-logs/schemas' => '/audit-log-schemas',
        '/audit-logs/settings' => '/audit-log-settings',
        '/token-vault/secrets' => '/vault-secrets',
        '/keys' => '/environment-keys',
        '/sign-in/social-providers' => '/social-connections',
        '/branding/appearance' => '/appearance',
        '/branding/whitelabel' => '/whitelabel',
        '/signals/source' => '/signals-source',
        '/domains' => '/environment-domain',
    ];

    /**
     * Every PUT kept on `/v1`, each a whole replacement of what its path names.
     *
     * @var array<string, string>
     */
    public const array WHOLE_REPLACEMENTS = [
        '/api-scopes/{id}' => 'defines one scope of an API, all of it',
        '/app-catalog/{id}' => 'saves a catalog entry, all of it',
        '/apps/{id}/manifest' => 'replaces the app\'s roles and permissions manifest',
        '/apps/{id}/scopes' => 'replaces the scopes an app may request',
        '/apps/{id}/api-key-prefix' => 'one setting, replaced',
        '/apps/{id}/backchannel-logout' => 'one setting, replaced',
        '/apps/{id}/client-credentials' => 'one setting, replaced',
        '/apps/{id}/token-exchange' => 'one setting, replaced',
        '/apps/{id}/token-lifetime' => 'one setting, replaced',
        '/appearance' => 'the environment\'s appearance, replaced',
        '/whitelabel' => 'the environment\'s white-label settings, replaced',
        '/audit-log-schemas/{id}' => 'one event schema, replaced',
        '/directories/{id}/credentials' => 'the directory\'s credentials, replaced',
        '/environment-role-assignments/{id}' => 'grants one environment role: creates or keeps the assignment',
        '/fga/schema' => 'the authorization model, replaced',
        '/frontend-keys/{id}/origins' => 'the allowed origins, replaced',
        '/organization-memberships/{id}/app-roles' => 'the member\'s roles in one app, replaced',
        '/role-assignments/{id}' => 'grants one role: creates or keeps the assignment',
        '/role-permissions/{id}' => 'grants one permission: creates or keeps it',
        '/service-connections/{id}' => 'creates or replaces a connection by its id',
        '/sign-in/self-service-signup' => 'one setting, replaced',
        '/sign-in/through' => 'one setting, replaced',
        '/user-property-namespaces/{id}' => 'one namespace\'s configuration, replaced',
    ];

    /**
     * Where one old operation lives on `/v1`: the method, the path with its one parameter as
     * `{id}`, which old parameter that `{id}` is, the old parameters now read from the
     * request's input (`old => public`), and — for routes that two operations share — the
     * fields that pick this one. Null when it is not on `/v1`.
     *
     * @return array{method: string, path: string, id: string|null, inputs: array<string, string>, when: string|null}|null
     */
    public static function target(string $method, string $old): ?array
    {
        $old = '/'.trim($old, '/');
        $key = strtoupper($method).' '.$old;

        if (array_key_exists($key, self::MOVED)) {
            $moved = self::MOVED[$key];

            if ($moved === null) {
                return null;
            }

            return self::shaped($moved[0], $moved[1], $moved[2] ?? [], $moved[3] ?? null);
        }

        $path = $old;

        foreach (self::RENAMED as $from => $to) {
            if ($path === $from || str_starts_with($path, $from.'/')) {
                $path = $to.substr($path, strlen($from));

                break;
            }
        }

        return self::shaped(strtoupper($method), $path, [], null);
    }

    /**
     * @param  array<string, string>  $inputs
     * @return array{method: string, path: string, id: string|null, inputs: array<string, string>, when: string|null}
     */
    private static function shaped(string $method, string $path, array $inputs, ?string $when): array
    {
        $id = null;

        $path = (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $match) use (&$id): string {
            $id ??= $match[1];

            return '{id}';
        }, $path, 1);

        return ['method' => $method, 'path' => $path, 'id' => $id, 'inputs' => $inputs, 'when' => $when];
    }
}
