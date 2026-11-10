<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Middleware\AuthenticateOrganizationApi;
use App\Platform\Actions\Principal\OrganizationKeyPrincipal;
use App\Platform\OrganizationCapabilities;
use App\Platform\Scopes\ScopeRegistry;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * Every scope an organization key (`cbid_ws_…`) may carry, and the capability each one needs
 * the key's ROLE to hold.
 *
 * TWO LOCKS, NOT ONE. An organization key has always carried a role, and the role still bounds
 * it: a scope never lifts a key above what its role may do. Scopes narrow it further — a
 * key that only ever creates projects and environments carries `id:projects:write` and
 * `id:environments:write`, and a leak of it cannot touch the team. A key minted with NO
 * scopes is bounded by its role alone, which is what every key minted before scopes
 * existed is, so nothing that works today stops working.
 *
 * The capability column is the explicit map from a scope to the console's own question
 * ({@see OrganizationCapabilities}), so the machine and the person get the same answer
 * from the same role: `id:members:read` is `read-members`, which a Developer key does not have
 * however it is scoped. Read by {@see AuthenticateOrganizationApi} on the route and by
 * {@see OrganizationKeyPrincipal} on every action, whichever door it came through.
 */
final class OrganizationScopes
{
    /**
     * @var array<string, array{label: string, description: string, capability: string|null}>
     */
    public const array SCOPES = [
        'id:organization:read' => [
            'label' => 'Read the organization',
            'description' => 'Read the organization, its projects and environments — and, for a role that may read billing, each project\'s plan.',
            'capability' => null,
        ],
        'id:projects:write' => [
            'label' => 'Manage projects',
            'description' => 'Create, rename, suspend and reactivate projects.',
            'capability' => 'manage-environments',
        ],
        'id:environments:write' => [
            'label' => 'Manage environments',
            'description' => 'Create environments, optionally with a first management key, and serve them on custom domains.',
            'capability' => 'manage-environments',
        ],
        'id:members:read' => [
            'label' => 'Read the team',
            'description' => 'List the organization\'s members and pending invitations.',
            'capability' => 'read-members',
        ],
        'id:members:write' => [
            'label' => 'Manage the team',
            'description' => 'Invite, re-role, remove and scope members; re-send and withdraw invitations.',
            'capability' => 'manage-members',
        ],
        'id:environment-keys:write' => [
            'label' => 'Manage environment keys',
            'description' => 'Mint and revoke management keys for the organization\'s environments.',
            'capability' => 'manage-environments',
        ],
        'id:organization-keys:write' => [
            'label' => 'Manage organization keys',
            'description' => 'Mint and revoke organization keys never wider than this one.',
            'capability' => 'manage-environments',
        ],
        'id:billing:write' => [
            'label' => 'Manage billing',
            'description' => 'Update billing details and manage stored payment methods for this organization.',
            'capability' => 'manage-billing',
        ],
        'id:organization:write' => [
            'label' => 'Change organization settings',
            'description' => 'Rename the organization.',
            'capability' => 'own-organization',
        ],
    ];

    /**
     * The scopes that also need the product's admission ({@see ProductAdmission}): reading the
     * organization's own products and running them.
     *
     * @var list<string>
     */
    public const array ADMITTED = ['id:organization:read', 'id:projects:write', 'id:environments:write', 'id:environment-keys:write', 'id:organization-keys:write'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SCOPES);
    }

    /** Whether $scope is one of these — by its new name or an old one ({@see ScopeRegistry}). */
    public static function knows(string $scope): bool
    {
        return self::resolve($scope) !== null;
    }

    /**
     * $scope's name here: the new name it is or stands for, or null when it is not an
     * organization scope. `keys:write` granted both kinds of key, so it resolves to the first
     * and {@see self::normalise()} keeps both.
     */
    public static function resolve(string $scope): ?string
    {
        foreach (ScopeRegistry::current($scope) as $name) {
            if (isset(self::SCOPES[$name])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * $scopes in the names this vocabulary uses, unknown ones left out.
     *
     * @param  iterable<mixed>  $scopes
     * @return list<string>
     */
    public static function normalise(iterable $scopes): array
    {
        return array_values(array_filter(ScopeRegistry::normalise($scopes), static fn (string $scope): bool => isset(self::SCOPES[$scope])));
    }

    /**
     * What a key minted with $scopes stores through v3.0: the v3 names AND the v2 name of
     * each, so the release still running while v3 rolls out (whose check is an exact match on
     * the v2 name) reads the key as it was minted. A v2 name that split (`keys:write` granted
     * organization AND environment keys) is stored only when every name it split into is held:
     * v2 must never read a key as wider than it is. v3 reads either spelling
     * ({@see ScopeRegistry::satisfiedBy()}); v3.1 drops the v2 names.
     *
     * @param  iterable<mixed>  $scopes
     * @return list<string>
     */
    public static function stored(iterable $scopes): array
    {
        $current = self::normalise($scopes);
        $legacy = [];

        foreach (ScopeRegistry::RENAMED as $old => $new) {
            if (array_diff($new, $current) === []) {
                $legacy[] = $old;
            }
        }

        return array_values(array_unique([...$current, ...$legacy]));
    }

    /** Whether $scope also needs the product's admission. */
    public static function admitted(string $scope): bool
    {
        return in_array(self::resolve($scope), self::ADMITTED, true);
    }

    public static function label(string $scope): string
    {
        return self::SCOPES[self::resolve($scope) ?? '']['label'] ?? $scope;
    }

    /** The capability $scope needs the role to hold — null for one any role may use. */
    public static function capability(string $scope): ?string
    {
        return self::SCOPES[self::resolve($scope) ?? '']['capability'] ?? null;
    }

    /**
     * Whether a key holding $role may use $scope at all — an unknown scope never.
     */
    public static function roleAllows(MembershipRole $role, string $scope): bool
    {
        return self::knows($scope) && self::holds(OrganizationCapabilities::forMachineRole($role), self::capability($scope));
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
            'read-environments' => $can->canReadEnvironments(),
            'own-organization' => $can->isOwner(),
            'manage-environments' => $can->canManageEnvironments(),
            'manage-members' => $can->canManageMembers(),
            'read-members' => $can->canReadMembers(),
            'read-billing' => $can->canReadBilling(),
            'manage-billing' => $can->canManageBilling(),
            default => false,
        };
    }
}
