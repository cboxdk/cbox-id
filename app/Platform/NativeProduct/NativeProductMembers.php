<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use App\Platform\Actions\ActionRefused;
use App\Platform\AppMemberRoles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/** Native app assignments only; shared membership and other products remain untouched. */
final readonly class NativeProductMembers
{
    public function __construct(private NativeProductClient $client, private PlatformRoot $root, private AppMemberRoles $roles) {}

    /** @return array{roles: list<string>, roles_version: string} */
    public function access(Membership $member): array
    {
        return $this->accessFor($member->organization_id, [$member->user_id])[$member->user_id];
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, array{roles: list<string>, roles_version: string}>
     */
    public function accessFor(string $organizationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->root->run(function () use ($organizationId, $userIds): array {
            $roles = Role::query()->where('client_id', $this->client->clientId())->orderBy('id')->get();
            $assignments = RoleAssignment::query()->where('organization_id', $organizationId)
                ->whereIn('user_id', $userIds)->whereIn('role_id', $roles->modelKeys())
                ->get(['user_id', 'role_id'])->groupBy('user_id');
            $result = [];
            foreach ($userIds as $userId) {
                $assigned = $roles->whereIn('id', $assignments->get($userId)?->pluck('role_id')->all() ?? []);
                $ids = $assigned->pluck('id')->all();
                sort($ids);
                $result[$userId] = ['roles' => array_values(array_filter($assigned->pluck('key')->all(), 'is_string')), 'roles_version' => hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR))];
            }

            return $result;
        }) ?? throw new ActionRefused('native_product_unavailable', 'ID product access could not be verified.', 503);
    }

    /** @param list<string> $keys */
    public function replace(Membership $member, array $keys, string $version, AuditActor $actor): void
    {
        $this->root->run(fn () => DB::transaction(function () use ($member, $keys, $version, $actor): void {
            $locked = app(TenantContext::class)->runAs(
                GenericTenant::of($member->organization_id),
                fn () => Membership::query()->whereKey($member->id)->lockForUpdate()->firstOrFail(),
            );
            if ($locked->role === MembershipRole::Owner) {
                throw new ActionRefused('not_manageable', 'The current owner’s access is managed through ownership transfer.', 422);
            }
            $this->roles->replace($locked, $this->client->clientId(), $keys, $version, $actor);
        }));
    }
}
