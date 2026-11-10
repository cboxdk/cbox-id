<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\PersonPrincipal;
use App\Platform\Scopes\ScopeRegistry;

/**
 * Every scope the account plane ({@see ActionPlane::Account}) asks for — what a person may
 * delegate to a token acting as themself ({@see PersonPrincipal}).
 *
 * Split by what a leak of the token could do: a token that tidies up its owner's sessions
 * has no business minting API keys or unlinking the social account they sign in with.
 * WHAT IS NOT HERE is as deliberate — no scope changes a password, enrols or removes a
 * second factor, or registers a passkey. Those are ceremonies the person performs in a
 * browser, and a token that could perform them would be a token that could take the
 * account over.
 */
final class AccountScopes
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const array SCOPES = [
        'account:apps:read' => ['label' => 'Read your app catalog', 'description' => 'List the published apps visible to you.'],
        'account:waitlists:write' => ['label' => 'Join service waitlists', 'description' => 'Join published early-access waitlists using your verified email. Does not purchase a plan or grant access.'],
        'account:profile:write' => [
            'label' => 'Change your profile',
            'description' => 'Change the display name on your account.',
        ],
        'account:sessions:write' => [
            'label' => 'Sign out your sessions',
            'description' => 'Sign out one of your sessions, or every one but this.',
        ],
        'account:applications:write' => [
            'label' => 'Withdraw application access',
            'description' => 'Withdraw an application\'s access to your account.',
        ],
        'account:api_keys:write' => [
            'label' => 'Manage your API keys',
            'description' => 'Create and revoke your own API keys for the apps built on this environment. A new key is shown once.',
        ],
        'account:sign_in:write' => [
            'label' => 'Manage your sign-in methods',
            'description' => 'Rename or remove one of your passkeys, remove your phone number for text-message codes, or disconnect a social account — never the last way you can sign in.',
        ],
        'account:pipes:write' => [
            'label' => 'Disconnect your connected services',
            'description' => 'Disconnect a third-party account (GitHub, Google, …) you connected so an app here could act through it.',
        ],
        'account:devices:write' => [
            'label' => 'Remove your devices',
            'description' => 'Remove one of your trusted devices.',
        ],
        'account:organizations:write' => [
            'label' => 'Leave your organizations',
            'description' => 'Leave an organization you are a member of. Never as its last owner.',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SCOPES);
    }

    public static function knows(string $scope): bool
    {
        return self::resolve($scope) !== null;
    }

    public static function label(string $scope): string
    {
        return self::SCOPES[self::resolve($scope) ?? $scope]['label'] ?? $scope;
    }

    /**
     * The name this vocabulary files $scope under — itself, or the old name a new one stands
     * for ({@see ScopeRegistry}) — or null when it is not one of these.
     */
    public static function resolve(string $scope): ?string
    {
        foreach (ScopeRegistry::equivalents($scope) as $name) {
            if (isset(self::SCOPES[$name])) {
                return $name;
            }
        }

        return null;
    }
}
