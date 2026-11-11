<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use App\Http\Middleware\AcceptPublicIds;
use App\Platform\Api\V1\V1Paths;
use App\Routing\PublicIdUrlGenerator;
use Illuminate\Routing\Route;

/**
 * WHICH KIND OF RECORD A ROUTE PARAMETER IS THE ID OF — for the console's URLs, which carry
 * public ids ({@see PublicIdUrlGenerator}), and for every route that reads one
 * ({@see AcceptPublicIds}).
 *
 * The protocol surfaces are left alone on purpose: an SSO callback, an ACS URL or an OAuth
 * path is registered with somebody else's identity provider exactly as it is, so its URL may
 * not change shape. So are tokens (`{token}`), keys that are names (`{key}` of a catalog
 * entry), and every parameter whose kind is not known for certain.
 */
final class RouteIds
{
    /** Route-name prefixes whose URLs are protocol or one-time-token URLs, never re-shaped. */
    private const array UNTOUCHED = [
        'sso.', 'oauth.', 'social.', 'api.', 'v1.', 'internal.', 'invitation.', 'organization.invite.',
        'magic.', 'password.', 'verification.', 'postal.', 'webhooks.postal', 'storage.', 'login.',
        'queue-monitor.', 'queue-metrics.', 'ai-assistant-connector.', 'legacy.', 'portal.enter',
        'docs', 'console.shell.', 'app-catalog.icon', 'whitelabel.', 'admin.', 'platform.moved',
    ];

    /**
     * A parameter's kind on every console route that has it, unless a route says otherwise
     * ({@see self::BY_ROUTE}).
     *
     * @var array<string, string>
     */
    private const array PARAMETERS = [
        'user' => 'user',
        'operator' => 'user',
        'organization' => 'organization',
        'org' => 'organization',
        'project' => 'project',
        'environment' => 'environment',
        'targetEnvironment' => 'environment',
        'client' => 'app',
        'connection' => 'sso_connection',
        'directory' => 'directory',
        'domain' => 'verified_domain',
        'invitation' => 'invitation',
        'role' => 'role',
        'permission' => 'permission',
        'api' => 'api',
        'webhook' => 'webhook_endpoint',
        'stream' => 'log_stream',
        'hook' => 'hook',
        'pipe' => 'pipe',
        'flag' => 'feature_flag',
        'campaign' => 'access_review',
        'item' => 'access_review_item',
        'policy' => 'sod_policy',
        'sync' => 'provisioning_target',
        'rule' => 'radar_rule',
        'approval' => 'approval',
        'secret' => 'vault_secret',
        'session' => 'session',
        'device' => 'device',
        'passkey' => 'passkey',
        'factor' => 'mfa_factor',
    ];

    /**
     * Where a parameter means something else on one route (or a family of routes, matched by
     * the end of the name): `null` leaves it bare.
     *
     * @var array<string, array<string, string|null>>
     */
    private const array BY_ROUTE = [
        'clients.secrets.revoke' => ['secret' => null],
        'vault.grants.destroy' => ['client' => null],
        'keys.frontend.destroy' => ['key' => 'publishable_key'],
        'keys.frontend.origins' => ['key' => 'publishable_key'],
        'environment.keys.destroy' => ['key' => 'environment_key'],
        'environment.keys.rotate' => ['key' => 'environment_key'],
        'orgs.settings.api-keys.destroy' => ['key' => 'environment_key'],
        'orgs.settings.api-keys.organization.destroy' => ['key' => 'organization_key'],
        'account.api-keys.revoke' => ['key' => 'customer_api_key'],
        'organizations.api-keys.revoke' => ['key' => 'customer_api_key'],
        // The workspace roster is addressed by membership; the environment's rosters by user.
        'orgs.settings.members.remove' => ['member' => 'membership'],
        'orgs.settings.members.access' => ['member' => 'membership'],
        'orgs.settings.members.role' => ['member' => 'membership'],
        'orgs.settings.members.transfer-ownership' => ['member' => 'membership'],
        'directory.members.remove' => ['member' => 'user'],
        'directory.members.access' => ['member' => 'user'],
        'directory.members.role' => ['member' => 'user'],
        'directory.members.transfer-ownership' => ['member' => 'user'],
        'organizations.members.remove' => ['member' => 'user'],
        'organizations.members.access' => ['member' => 'user'],
        'organizations.members.role' => ['member' => 'user'],
        'organizations.members.transfer-ownership' => ['member' => 'user'],
        'support-sessions.end' => ['session' => 'support_session'],
        'support.launch' => ['session' => 'support_session'],
        // A social sign-in provider, a pipe's connection, a person's linked account: none is
        // an SSO connection.
        'social-providers.update' => ['connection' => null],
        'social-providers.destroy' => ['connection' => null],
        'social-providers.disable' => ['connection' => null],
        'social-providers.enable' => ['connection' => null],
        'pipes.connections.destroy' => ['connection' => null],
        'account.sign-in-methods.connect' => ['connection' => null],
    ];

    /** The kind of record `$parameter` is the id of on `$route`; null when it is none we know. */
    public static function typeFor(Route $route, string $parameter): ?string
    {
        $uri = $route->uri();

        if ($uri === 'api/v1' || str_starts_with($uri, 'api/v1/')) {
            return self::legacyApi($route, $parameter);
        }

        $name = $route->getName();

        if ($name === null || self::untouched($name)) {
            return null;
        }

        foreach (self::BY_ROUTE as $suffix => $overrides) {
            if (($name === $suffix || str_ends_with($name, '.'.$suffix)) && array_key_exists($parameter, $overrides)) {
                return $overrides[$parameter];
            }
        }

        return self::PARAMETERS[$parameter] ?? null;
    }

    /**
     * An `/api/v1` path's parameter: the kind of the `/v1` resource its operation lives at,
     * or of the field it became there.
     */
    public static function legacyApi(Route $route, string $parameter): ?string
    {
        $old = '/'.ltrim(substr($route->uri(), strlen('api/v1')), '/');

        foreach ($route->methods() as $method) {
            if (! is_string($method) || $method === 'HEAD') {
                continue;
            }

            $target = V1Paths::target($method, $old);

            if ($target === null) {
                continue;
            }

            if ($parameter === $target['id']) {
                return self::v1Id($target['path'], $parameter);
            }

            if (isset($target['inputs'][$parameter])) {
                return self::parameter($target['inputs'][$parameter]);
            }

            return self::parameter($parameter);
        }

        return self::parameter($parameter);
    }

    /**
     * The kind of a `/v1` path's `{id}`, which stands for the operation's old parameter
     * `$old`: the field's kind when that parameter names one (`user_id`), else the kind of
     * the resource the path names first (`/organizations/{id}/…`).
     */
    public static function v1Id(string $path, ?string $old): ?string
    {
        if ($old !== null && $old !== 'id') {
            return self::parameter($old) ?? (str_ends_with($old, '_id') && $old !== 'client_id' && ! str_starts_with($old, 'client') ? self::resourceOf($path) : null);
        }

        return self::resourceOf($path);
    }

    /** A parameter or field named for a kind (`user_id`); null for any other name. */
    public static function parameter(string $name): ?string
    {
        return IdNames::field($name);
    }

    private static function resourceOf(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        if (in_array($segments[0] ?? '', ['me', 'admin'], true) && count($segments) > 1) {
            array_shift($segments);
        }

        return isset($segments[0]) ? IdNames::resource($segments[0]) : null;
    }

    private static function untouched(string $name): bool
    {
        foreach (self::UNTOUCHED as $prefix) {
            if ($name === rtrim($prefix, '.') || str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
