<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The catalog of permissions ("scopes") an app can request — the plain-language
 * answer to "what should the scopes be?". Two families:
 *
 *  - Sign-in (OIDC): what an app learns about the person signing in. These are the
 *    OpenID Connect standard scopes the platform's /authorize + token endpoints
 *    honour (see laravel-id config `oauth.dynamic_registration.allowed_scopes`).
 *  - Platform API: what a machine-to-machine app may DO against the Cbox ID API.
 *    Each maps to a real `scope:` middleware on an API route (see routes/api.php).
 *
 * The form offers these as a described picker instead of a blank text box, and the
 * app still allows a custom scope for anything a host adds beyond this catalog.
 */
final class ScopeCatalog
{
    public const SIGN_IN = 'Sign-in';

    public const PLATFORM_API = 'Platform API';

    /**
     * @return list<array{key: string, label: string, description: string, category: string, recommended: bool}>
     */
    public function all(): array
    {
        return [
            ['key' => 'openid', 'label' => 'Sign in', 'description' => 'Confirm who the person is. Required for single sign-on.', 'category' => self::SIGN_IN, 'recommended' => true],
            ['key' => 'profile', 'label' => 'Basic profile', 'description' => "The person's name and profile details.", 'category' => self::SIGN_IN, 'recommended' => true],
            ['key' => 'email', 'label' => 'Email address', 'description' => "The person's email address.", 'category' => self::SIGN_IN, 'recommended' => true],
            ['key' => 'offline_access', 'label' => 'Stay signed in', 'description' => 'Keep them signed in with refresh tokens, so they need not log in again.', 'category' => self::SIGN_IN, 'recommended' => false],
            ['key' => 'vault.manage', 'label' => 'Manage stored secrets', 'description' => 'Create, rotate and revoke downstream credentials in the Token Vault.', 'category' => self::PLATFORM_API, 'recommended' => false],
            ['key' => 'vault.lease', 'label' => 'Use stored secrets', 'description' => 'Fetch a stored credential to call a downstream service.', 'category' => self::PLATFORM_API, 'recommended' => false],
            ['key' => 'apps.manifest', 'label' => 'Publish its own manifest', 'description' => 'Let this app push its own roles &amp; permissions manifest to Cbox ID.', 'category' => self::PLATFORM_API, 'recommended' => false],
        ];
    }

    /**
     * The catalog grouped by category, in display order.
     *
     * @return array<string, list<array{key: string, label: string, description: string, category: string, recommended: bool}>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->all() as $scope) {
            $grouped[$scope['category']][] = $scope;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (array $s): string => $s['key'], $this->all());
    }

    /**
     * Sensible defaults for a browser login / SSO app: the identity basics.
     *
     * @return list<string>
     */
    public function signInDefaults(): array
    {
        return ['openid', 'profile', 'email'];
    }
}
