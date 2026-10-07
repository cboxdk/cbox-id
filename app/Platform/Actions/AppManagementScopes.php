<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\EnumManagementScopes;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;

/**
 * Every scope a management key may carry: the framework's core set
 * ({@see EnvironmentApiScope}) plus the scopes this app's own actions guard.
 *
 * Bound as {@see ManagementScopes}, so the framework refuses to mint a key carrying
 * anything not listed here, and the key form, the middleware and the action runner all
 * read the same vocabulary. An area that becomes actions adds its scopes to
 * {@see self::APP_SCOPES} — with the label and description the key form shows — and the
 * action contract test fails until it does.
 */
class AppManagementScopes extends EnumManagementScopes
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const array APP_SCOPES = [
        'keys:read' => [
            'label' => 'Read management keys',
            'description' => 'List this environment\'s management keys: names, scopes, expiry and last use — never their values.',
        ],
        'keys:write' => [
            'label' => 'Manage management keys',
            'description' => 'Mint, rotate and revoke management keys, never wider than the key doing it.',
        ],
        'webhooks:read' => [
            'label' => 'Read webhooks',
            'description' => 'List this environment\'s webhook endpoints: where they point, what they subscribe to and whether they are paused — never their signing secrets.',
        ],
        'webhooks:write' => [
            'label' => 'Manage webhooks',
            'description' => 'Register, repoint, pause, resume, re-key and delete webhook endpoints. A new or rotated signing secret is shown once.',
        ],
        'hooks:read' => [
            'label' => 'Read inline hooks',
            'description' => 'List the inline hooks called during sign-in and token issuance, and whether each is active.',
        ],
        'hooks:write' => [
            'label' => 'Manage inline hooks',
            'description' => 'Register, pause, activate and remove inline hooks — endpoints that can add claims to tokens or refuse a sign-in.',
        ],
        'log_streams:read' => [
            'label' => 'Read log streams',
            'description' => 'List the SIEM destinations this environment\'s audit trail is streamed to, and whether each is enabled.',
        ],
        'log_streams:write' => [
            'label' => 'Manage log streams',
            'description' => 'Create, disable, resume and delete audit log streams. A generated signing key is shown once.',
        ],
        'events:read' => [
            'label' => 'Read events',
            'description' => 'Read this environment\'s domain events — the same events webhooks deliver — with a cursor.',
        ],
        'audit:read' => [
            'label' => 'Read the audit log',
            'description' => 'Read this environment\'s audit trail: who did what, to what, and when.',
        ],
        'signin:read' => [
            'label' => 'Read sign-in rules',
            'description' => 'Read the sign-in rules, the social sign-in providers and the legacy login declaration — never a provider\'s secret.',
        ],
        'signin:write' => [
            'label' => 'Change how people sign in',
            'description' => 'Change password, MFA and SSO rules, self-service sign-up, social sign-in providers and the legacy login approval.',
        ],
        'frontend_keys:read' => [
            'label' => 'Read frontend keys',
            'description' => 'List the publishable keys browser apps present to the Frontend API, with their allowed origins.',
        ],
        'frontend_keys:write' => [
            'label' => 'Manage frontend keys',
            'description' => 'Create publishable keys, change which origins may present them, and revoke them.',
        ],
        'saml_apps:read' => [
            'label' => 'Read SAML applications',
            'description' => 'List the applications that trust this environment as their SAML identity provider — never their certificates.',
        ],
        'saml_apps:write' => [
            'label' => 'Manage SAML applications',
            'description' => 'Register, change and remove the applications people sign in to with their account here.',
        ],
        'branding:read' => [
            'label' => 'Read branding',
            'description' => 'Read the hosted sign-in theme and branding of the environment and its organizations.',
        ],
        'branding:write' => [
            'label' => 'Change branding',
            'description' => 'Change the hosted sign-in theme and branding of the environment and its organizations.',
        ],
        'domains:read' => [
            'label' => 'Read custom domains',
            'description' => 'Read this environment\'s custom domain and the DNS record that proves it.',
        ],
        'domains:write' => [
            'label' => 'Manage custom domains',
            'description' => 'Add, verify and remove the custom domain this environment is served on.',
        ],
        'approvals:read' => [
            'label' => 'Read agent requests',
            'description' => 'List the pending requests from agents to act as one of this environment\'s people (OIDC CIBA): which app, for whom, and what it asks.',
        ],
        'approvals:write' => [
            'label' => 'Deny agent requests',
            'description' => 'Deny a pending agent request. Denying grants nothing; approving is only ever the person\'s own act.',
        ],
    ];

    public function knows(string $scope): bool
    {
        return isset(self::APP_SCOPES[$scope]) || parent::knows($scope);
    }

    public function all(): array
    {
        return array_values(array_unique([...parent::all(), ...array_keys(self::APP_SCOPES)]));
    }

    public function offerable(): array
    {
        return array_values(array_unique([...parent::offerable(), ...array_keys(self::APP_SCOPES)]));
    }

    /** The label the key form shows for $scope. */
    public static function label(string $scope): string
    {
        return self::APP_SCOPES[$scope]['label'] ?? EnvironmentApiScope::tryFrom($scope)?->label() ?? $scope;
    }

    public static function writes(string $scope): bool
    {
        return ! str_ends_with($scope, ':read');
    }
}
