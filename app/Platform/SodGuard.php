<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;

/**
 * The console's side of the Segregation-of-Duties contract.
 *
 * The framework publishes `wouldViolate()` as a PRE-GRANT gate the host is expected to
 * call before assigning a role — the contract's own docblock says so, and the docs list
 * "the host must call it" under honest limits. Nothing lied; the console simply never
 * called it, so an admin could create on the Members page exactly the toxic combination
 * the Governance page reports. This is the one place that calls it, and that turns a
 * boolean into a refusal a human can act on.
 */
final class SodGuard
{
    public function __construct(
        private readonly SegregationOfDuties $sod,
        private readonly Roles $roles,
    ) {}

    /**
     * Refuse assigning `$roleId` to a subject who already holds a conflicting role, or
     * null when the grant is clean.
     */
    public function refuse(string $organizationId, string $userId, string $roleId): ?SodRefusal
    {
        if (! $this->sod->wouldViolate($organizationId, $userId, $roleId)) {
            return null;
        }

        $held = array_map(
            static fn (RoleAssignment $assignment): string => $assignment->role_id,
            $this->roles->assignmentsForSubject($organizationId, $userId),
        );

        return $this->describe($organizationId, $roleId, $held);
    }

    /**
     * Refuse a PROPOSED SET of roles that is internally toxic, or null when it is not.
     *
     * The gate above cannot see this case: an invitee holds nothing yet, so granting any
     * single role from a mutually-exclusive set is fine — it is the combination parked
     * for them that violates, and it only surfaces once they accept, in a controller
     * with no UI to report it.
     *
     * @param  list<string>  $roleIds
     */
    public function refuseSet(string $organizationId, array $roleIds): ?SodRefusal
    {
        foreach ($this->applicablePolicies($organizationId) as $policy) {
            $inSet = array_values(array_intersect($roleIds, $policy->role_ids));

            if (count($inSet) >= 2) {
                $proposed = array_shift($inSet);

                return $this->refusalFor($policy, $proposed, $inSet);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $held
     */
    private function describe(string $organizationId, string $roleId, array $held): SodRefusal
    {
        foreach ($this->applicablePolicies($organizationId) as $policy) {
            if (! in_array($roleId, $policy->role_ids, true)) {
                continue;
            }

            $others = array_values(array_diff(array_intersect($policy->role_ids, $held), [$roleId]));

            if ($others !== []) {
                return $this->refusalFor($policy, $roleId, $others);
            }
        }

        // wouldViolate() said yes, so a policy did trip; only a concurrent change to the
        // policy set can land here. Report the refusal rather than silently allowing it.
        return new SodRefusal('a segregation-of-duties policy', $this->name($roleId), []);
    }

    /**
     * @param  list<string>  $conflictingRoleIds
     */
    private function refusalFor(SodPolicy $policy, string $proposedRoleId, array $conflictingRoleIds): SodRefusal
    {
        return new SodRefusal(
            $policy->name,
            $this->name($proposedRoleId),
            array_map(fn (string $id): string => $this->name($id), $conflictingRoleIds),
        );
    }

    private function name(string $roleId): string
    {
        $name = Role::query()->whereKey($roleId)->value('name');

        return is_string($name) && $name !== '' ? $name : $roleId;
    }

    /**
     * Active policies governing this org: its own, plus every environment-wide one.
     * Mirrors the framework's own applicability rule.
     *
     * @return list<SodPolicy>
     */
    private function applicablePolicies(string $organizationId): array
    {
        return array_values(SodPolicy::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->get()
            ->all());
    }
}
