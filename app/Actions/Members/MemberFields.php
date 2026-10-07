<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Roles\RoleFields;
use App\Http\Resources\Environment\MemberResource;
use App\Platform\Actions\ActionRefused;
use App\Platform\GrantAccessRole;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Membership;

/**
 * Shared lookups for the member actions. A helper, not an action.
 *
 * A member is addressed by USER id — the id the caller already holds — inside the
 * organization the path names. A person who is a member somewhere else, named under this
 * organization, is not found here: the lookup is the pair, never the user alone.
 */
final class MemberFields
{
    /**
     * @throws ActionRefused
     */
    public static function find(string $organizationId, string $userId): Membership
    {
        return app(Memberships::class)->of($organizationId, $userId) ?? throw ActionRefused::notFound('member');
    }

    /**
     * `Member` in the spec: the membership's authority and the person's name and address.
     *
     * @return array<string, mixed>
     */
    public static function present(Membership $membership): array
    {
        return MemberResource::from($membership, app(Subjects::class)->find($membership->user_id));
    }

    /**
     * The access roles a list of references names, each one a role THIS organization may
     * hold from the environment's plane — its own, the environment's shared ones and those
     * of the apps it can use, staff-only ones included. Every reference is resolved before
     * anything is granted, so one bad name refuses the lot.
     *
     * @param  array<mixed>  $references  Ids, or manifest keys of `$clientId`'s app.
     * @return list<Role>
     *
     * @throws ActionRefused
     */
    public static function assignableRoles(string $organizationId, array $references, ?string $clientId, string $field = 'roles'): array
    {
        $catalog = app(OrgAccessRoles::class);
        $roles = [];

        foreach (array_unique(array_filter($references, static fn (mixed $ref): bool => is_string($ref) && $ref !== '')) as $reference) {
            $role = RoleFields::reference((string) $reference, $clientId)
                ?? throw ActionRefused::because('unknown_role', "No role [{$reference}] exists in this environment.", $field);

            if (! $catalog->isAssignable($organizationId, $role->id)) {
                throw self::notAssignable($role, $field);
            }

            $roles[$role->id] = $role;
        }

        return array_values($roles);
    }

    /**
     * Grant one access role inside one organization with the ENVIRONMENT's authority
     * ({@see GrantAccessRole::grant()}), segregation of duties asked first. A conflict is a
     * 409 naming both roles; the action's transaction takes back anything granted before it.
     *
     * @throws ActionRefused
     */
    public static function grant(string $organizationId, string $userId, Role $role, string $field = 'role_id'): void
    {
        try {
            $refusal = app(GrantAccessRole::class)->grant($organizationId, $userId, $role->id, GrantSource::Manual);
        } catch (GrantRefused $refused) {
            throw new ActionRefused('role_conflict', $refused->getMessage(), 409, $field);
        } catch (UnknownRole) {
            throw ActionRefused::notFound('role');
        }

        if ($refusal !== null) {
            throw new ActionRefused('role_conflict', $refusal->message(), 409, $field);
        }
    }

    public static function notAssignable(Role $role, string $field = 'role_id'): ActionRefused
    {
        return ActionRefused::because(
            'role_not_assignable',
            "The role [{$role->name}] cannot be held in this organization — it belongs to another organization, or to an app this organization cannot use.",
            $field,
        );
    }
}
