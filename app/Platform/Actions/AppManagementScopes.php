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
