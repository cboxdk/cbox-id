<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Middleware\AuthenticateWorkspaceApi;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * Every scope a workspace key (`cbid_ws_…`) may carry, and the capability each one needs
 * the key's ROLE to hold.
 *
 * TWO LOCKS, NOT ONE. A workspace key has always carried a role, and the role still bounds
 * it: a scope never lifts a key above what its role may do. Scopes narrow it further — a
 * key that only ever creates projects and environments carries `projects:write` and
 * `environments:write`, and a leak of it cannot touch the team. A key minted with NO
 * scopes is bounded by its role alone, which is what every key minted before scopes
 * existed is, so nothing that works today stops working.
 *
 * The capability column is the explicit map from a scope to the console's own question
 * ({@see OrganizationCapabilities}), so the machine and the person get the same answer
 * from the same role: `team:read` is `read-members`, which a Developer key does not have
 * however it is scoped. Read by {@see AuthenticateWorkspaceApi} on the route and by
 * {@see WorkspaceKeyPrincipal} on every action, whichever door it came through.
 */
final class WorkspaceScopes
{
    /**
     * @var array<string, array{label: string, description: string, capability: string|null}>
     */
    public const array SCOPES = [
        'workspace:read' => [
            'label' => 'Read the workspace',
            'description' => 'Read the workspace, its projects and environments — and, for a role that may read billing, each project\'s plan.',
            'capability' => null,
        ],
        'projects:write' => [
            'label' => 'Manage projects',
            'description' => 'Create, rename, suspend and reactivate projects.',
            'capability' => 'manage-environments',
        ],
        'environments:write' => [
            'label' => 'Create environments',
            'description' => 'Create environments, optionally with a first management key.',
            'capability' => 'manage-environments',
        ],
        'team:read' => [
            'label' => 'Read the team',
            'description' => 'List the workspace\'s members and pending invitations.',
            'capability' => 'read-members',
        ],
        'team:write' => [
            'label' => 'Manage the team',
            'description' => 'Invite, re-role, remove and scope members; re-send and withdraw invitations.',
            'capability' => 'manage-members',
        ],
        'keys:write' => [
            'label' => 'Manage keys',
            'description' => 'Mint and revoke management keys for the workspace\'s environments, and workspace keys never wider than this one.',
            'capability' => 'manage-environments',
        ],
        'settings:write' => [
            'label' => 'Change workspace settings',
            'description' => 'Rename the workspace.',
            'capability' => 'manage-members',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SCOPES);
    }

    public static function knows(string $scope): bool
    {
        return isset(self::SCOPES[$scope]);
    }

    public static function label(string $scope): string
    {
        return self::SCOPES[$scope]['label'] ?? $scope;
    }

    /** The capability $scope needs the role to hold — null for one any role may use. */
    public static function capability(string $scope): ?string
    {
        return self::SCOPES[$scope]['capability'] ?? null;
    }

    /**
     * Whether a key holding $role may use $scope at all — an unknown scope never.
     */
    public static function roleAllows(MembershipRole $role, string $scope): bool
    {
        return self::knows($scope) && self::holds(OrganizationCapabilities::of($role), self::capability($scope));
    }

    /**
     * Whether these capabilities include $capability (null: no capability needed).
     *
     * `default => false` on purpose: a capability nobody implemented is refused rather
     * than admitted, so a scope or gate naming a typo fails closed.
     */
    public static function holds(OrganizationCapabilities $can, ?string $capability): bool
    {
        return match ($capability) {
            null => true,
            'manage-environments' => $can->canManageEnvironments(),
            'manage-members' => $can->canManageMembers(),
            'read-members' => $can->canReadMembers(),
            'read-billing' => $can->canReadBilling(),
            'manage-billing' => $can->canManageBilling(),
            default => false,
        };
    }
}
