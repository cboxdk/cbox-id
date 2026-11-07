<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\FrameworkMessage;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\AccessControl\RoleGrantLock;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Support\Facades\DB;

/** One app's manual assignments, replaced atomically without changing organization tiers. */
final readonly class AppMemberRoles
{
    public function __construct(private OrgAccessRoles $catalog, private GrantAccessRole $grants, private AuditLog $audit) {}

    /** @param list<string> $keys
     * @return list<RoleAssignment>
     */
    public function replace(Membership $member, string $clientId, array $keys, string $expectedVersion, AuditActor $actor): array
    {
        return DB::transaction(function () use ($member, $clientId, $keys, $expectedVersion, $actor): array {
            // Serialize replacements, including an initially empty role set.
            $member = app(TenantContext::class)->runAs(
                GenericTenant::of($member->organization_id),
                fn (): Membership => Membership::query()->whereKey($member->id)->lockForUpdate()->firstOrFail(),
            );
            // Direct grants and directory sync do not take the membership lock.
            // Keep the version snapshot and replacement under their subject lock too.
            RoleGrantLock::forSubject($member->user_id);
            // Batch replacements share role rows across subjects. Lock the catalog
            // in a stable order before either reading the version or changing grants.
            Role::query()->where('client_id', $clientId)->orderBy('id')->lockForUpdate()->get();
            $existing = RoleAssignment::query()->where('organization_id', $member->organization_id)->where('user_id', $member->user_id)
                ->whereIn('role_id', Role::query()->where('client_id', $clientId)->select('id'))->get();
            $ids = array_values($existing->map(static fn (RoleAssignment $assignment): string => $assignment->role_id)->all());
            sort($ids);

            if (! hash_equals(hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR)), $expectedVersion)) {
                throw new ActionRefused('app_roles_changed', 'This member\'s roles changed. Reload their access before saving.', 409, 'version');
            }

            $desired = [];
            foreach (array_unique($keys) as $key) {
                $role = Role::query()->where('client_id', $clientId)->where('key', $key)->whereNull('orphaned_at')->first();
                if ($role === null || ! $this->catalog->isTenantAssignable($member->organization_id, $role->id)) {
                    throw ActionRefused::because('role_not_assignable', 'This app does not offer that role to this organization.', 'roles');
                }
                $desired[$role->id] = $role;
            }

            foreach ($existing as $assignment) {
                if (! isset($desired[$assignment->role_id])) {
                    if (! $this->catalog->isTenantAssignable($member->organization_id, $assignment->role_id)
                        && Role::query()->whereKey($assignment->role_id)->where('tenant_assignable', false)->exists()) {
                        throw ActionRefused::because('role_not_assignable', 'A staff role must be changed by its environment administrator.', 'roles');
                    }
                    if ($assignment->source !== GrantSource::Manual) {
                        throw ActionRefused::because('role_managed_externally', 'This role is managed by another source. Change it at its source.', 'roles');
                    }
                    $this->grants->revoke($member->organization_id, $member->user_id, $assignment->role_id);
                }
            }

            foreach ($desired as $role) {
                if (in_array($role->id, $ids, true)) {
                    continue;
                }
                try {
                    $refusal = $this->grants->grantAsTenant($member->organization_id, $member->user_id, $role->id);
                } catch (GrantRefused $error) {
                    throw new ActionRefused('role_conflict', FrameworkMessage::from($error), 409, 'roles');
                }
                if ($refusal !== null) {
                    throw new ActionRefused('role_conflict', $refusal->sourceMessage(), 409, 'roles');
                }
            }

            $after = array_keys($desired);
            sort($after);
            if ($ids !== $after) {
                $this->audit->record(new AuditEvent(
                    action: 'member.app_roles_changed', actorType: $actor->type, actorId: $actor->id,
                    organizationId: $member->organization_id, targetType: 'user', targetId: $member->user_id,
                    context: ['client_id' => $clientId, 'from' => $ids, 'to' => $after],
                ));
            }

            return array_values(RoleAssignment::query()->where('organization_id', $member->organization_id)->where('user_id', $member->user_id)
                ->whereIn('role_id', $after)->orderBy('role_id')->get()->all());
        });
    }
}
