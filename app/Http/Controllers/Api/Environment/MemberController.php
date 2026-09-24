<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\AddMemberRequest;
use App\Http\Requests\Api\Environment\ChangeMemberRoleRequest;
use App\Http\Requests\Api\Environment\TransferOwnershipRequest;
use App\Http\Resources\Environment\MemberResource;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefusalReason;
use App\Platform\Membership\MembershipRefused;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Environment plane › an organization's members: the roster, adding an existing user,
 * changing a tier, removing, and handing the organization to a new owner.
 *
 * Every write goes through the framework's {@see Memberships} (and the app's
 * {@see MembershipLifecycle} for ownership), which write the membership events
 * (`membership.created|updated|deleted`) and the audit entries. Members are addressed by
 * USER id, the id the caller already has.
 */
final class MemberController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request, string $id, TenantContext $tenants, Subjects $subjects): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        [$limit, $after] = $this->cursor($request);

        /*
         * Memberships are TENANT-owned: with no tenant in context the scope answers nothing,
         * so the read runs as this organization — and binds it in the WHERE clause as well,
         * so the roster stays this organization's wherever tenant scoping is suspended.
         */
        $rows = $tenants->runAs(GenericTenant::of($organization->id), fn (): Collection => $this->pageOf(
            Membership::query()->where('organization_id', $organization->id),
            $limit,
            $after,
        )->get());

        // One query for the people on this page, not one per row.
        $people = $subjects->findMany(array_values(array_map(
            static fn (Membership $m): string => $m->user_id,
            $rows->take($limit)->all(),
        )));

        return $this->page($rows, $limit, fn (Membership $m): array => MemberResource::from($m, $people[$m->user_id] ?? null));
    }

    /**
     * Add an existing user. Idempotent for the same tier: repeating the request answers 200
     * with the membership it already made. A person who is a member on a different tier is
     * a 409 — change the tier with PATCH, which says what it does.
     */
    public function store(AddMemberRequest $request, string $id, Memberships $memberships, Subjects $subjects): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        $userId = $request->userId();

        if (User::query()->whereKey($userId)->doesntExist()) {
            return $this->refuse('user_not_found', 'No user with that user_id exists in this environment.', 422);
        }

        $existing = $memberships->of($organization->id, $userId);

        if ($existing !== null) {
            return $existing->role === $request->role()
                ? $this->item(MemberResource::from($existing, $subjects->find($userId)))
                : $this->refuse('already_member', 'That user is already a member, as '.$existing->role->value.'. Change their tier with PATCH.', 409);
        }

        $membership = $memberships->add($organization->id, $userId, $request->role());

        return $this->item(MemberResource::from($membership, $subjects->find($userId)), 201);
    }

    public function update(ChangeMemberRoleRequest $request, string $id, string $userId, Memberships $memberships, Subjects $subjects): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null || $memberships->of($organization->id, $userId) === null) {
            return $this->notFound('member');
        }

        try {
            $membership = $memberships->changeRole($organization->id, $userId, $request->role());
        } catch (LastOwner) {
            return $this->lastOwner();
        }

        return $this->item(MemberResource::from($membership, $subjects->find($userId)));
    }

    /**
     * Remove a member — and every role they held in the organization, which the framework
     * drops with the membership. Refused for the last owner.
     */
    public function destroy(string $id, string $userId, Memberships $memberships): JsonResponse|Response
    {
        $organization = $this->organization($id);

        if ($organization === null || $memberships->of($organization->id, $userId) === null) {
            return $this->notFound('member');
        }

        try {
            $memberships->remove($organization->id, $userId);
        } catch (LastOwner) {
            return $this->lastOwner();
        }

        return response()->noContent();
    }

    /**
     * Make a member the owner.
     *
     * With one current owner this is the framework's own transfer — both rows locked, the
     * outgoing owner stays on as an admin. With none (an organization created without one)
     * or several (from before ownership was transfer-only), there is nobody single to hand
     * over FROM, so every current owner steps down to admin: the environment console's
     * "Make owner", through the same {@see MembershipLifecycle}.
     */
    public function transferOwnership(TransferOwnershipRequest $request, string $id, MembershipLifecycle $lifecycle, Memberships $memberships, Subjects $subjects): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        $to = $request->userId();
        $owners = $memberships->owners($organization->id);

        try {
            $lifecycle->transferOwnership($organization->id, $to, count($owners) === 1 ? $owners[0] : null, null);
        } catch (MembershipRefused $refused) {
            return $this->refuse(
                $refused->reason->value,
                $refused->getMessage(),
                $refused->reason === MembershipRefusalReason::NotAMember ? 422 : 409,
            );
        }

        $membership = $memberships->of($organization->id, $to);

        return $membership === null
            ? $this->notFound('member')
            : $this->item(MemberResource::from($membership, $subjects->find($to)));
    }

    private function lastOwner(): JsonResponse
    {
        return $this->refuse(
            'last_owner',
            'An organization must keep its owner. Transfer ownership to someone else first.',
            409,
        );
    }
}
