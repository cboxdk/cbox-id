<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\SendInvitationRequest;
use App\Http\Resources\Environment\InvitationResource;
use App\Models\InvitationContext;
use App\Models\InvitationRoleGrant;
use App\Platform\CurrentEnvironment;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Models\Invitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Environment plane › an organization's invitations: list the pending ones, send, re-send
 * and withdraw — all through {@see OrganizationInvitations}, the service every console
 * invite form uses, so an invitation from an app's backend parks its roles, carries its
 * way back to the app and can be re-sent exactly like one from a console.
 *
 * ACCESS ROLES ON AN INVITATION ARE THE TENANT PLANE'S. Accepting is the invitee's act
 * inside the organization, and the service grants parked roles as the tenant — so a
 * staff-only role can never ride in on one. Where a console quietly drops such a role from
 * a picker, this refuses it with a 422: an API caller that sent a role and got a 201 would
 * reasonably believe it will be granted. Grant a staff role to the member after they join,
 * with `PUT …/members/{userId}/roles/{roleId}`.
 */
final class InvitationController extends Controller
{
    use PaginatesEnvironmentResources;
    use ResolvesRoleReferences;

    public function index(Request $request, string $id): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        [$limit, $after] = $this->cursor($request);

        $rows = $this->pageOf(
            Invitation::query()
                ->where('organization_id', $organization->id)
                ->where('status', InvitationStatus::Pending->value)
                ->where('expires_at', '>', now()),
            $limit,
            $after,
        )->get();

        return $this->presentPage($organization->id, $rows, $limit);
    }

    public function store(
        SendInvitationRequest $request,
        string $id,
        OrganizationInvitations $invitations,
        OrgAccessRoles $catalog,
        CurrentEnvironment $environment,
    ): JsonResponse {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        $roleIds = [];
        $offered = null;

        foreach ($request->roleReferences() as $reference) {
            $role = $this->role($reference, $request->clientId());

            if ($role === null) {
                return $this->refuse('unknown_role', "No role [{$reference}] exists in this environment.", 422);
            }

            $offered ??= $catalog->tenantAssignable($organization->id)->pluck('id')->all();

            if (! in_array($role->id, $offered, true)) {
                return $this->refuse('role_not_assignable', $this->notOfferedMessage($role), 422);
            }

            $roleIds[] = $role->id;
        }

        $inviter = new Inviter(null, $request->inviterName() ?? $this->appName($request->clientId()) ?? $environment->name() ?? 'Your team');

        try {
            $sent = $invitations->send($request->toInvitation($organization->id, $inviter, array_values(array_unique($roleIds))));
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return $this->item($this->presentSent($sent), 201);
    }

    /**
     * Withdraw a pending invitation. Its link stops working and the roles parked for it go
     * with it.
     */
    public function destroy(string $id, string $invitationId, OrganizationInvitations $invitations): JsonResponse|Response
    {
        $organization = $this->organization($id);

        if ($organization === null || $this->invitation($organization->id, $invitationId) === null) {
            return $this->notFound('invitation');
        }

        try {
            $invitations->revoke($organization->id, $invitationId, null);
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return response()->noContent();
    }

    /**
     * Mail a pending invitation again, on a FRESH link: the server keeps only a hash of the
     * old one, so there is nothing to re-send. The answer is the new invitation — its id
     * replaces the old one, which stops working.
     */
    public function resend(string $id, string $invitationId, OrganizationInvitations $invitations, CurrentEnvironment $environment): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('invitation');
        }

        $invitation = $this->invitation($organization->id, $invitationId);

        if ($invitation === null) {
            return $this->notFound('invitation');
        }

        $context = InvitationContext::query()
            ->where('organization_id', $organization->id)
            ->where('invitation_id', $invitation->id)
            ->first();

        $inviter = new Inviter(null, $context->invited_by_name ?? $environment->name() ?? 'Your team');

        try {
            $sent = $invitations->resend($organization->id, $invitation->id, $inviter);
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return $this->item($this->presentSent($sent));
    }

    /** Any invitation with this id IN this organization, whatever its state — bound in the query. */
    private function invitation(string $organizationId, string $invitationId): ?Invitation
    {
        return Invitation::query()
            ->whereKey($invitationId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    private function refused(InvitationRefused $refused): JsonResponse
    {
        $status = match ($refused->reason) {
            InvitationRefusalReason::AlreadyMember,
            InvitationRefusalReason::AccessRoleConflict,
            InvitationRefusalReason::NotPending => 409,
            InvitationRefusalReason::TooSoon => 429,
            InvitationRefusalReason::MailFailed => 503,
            InvitationRefusalReason::RoleNotOffered,
            InvitationRefusalReason::ReturnWithoutApp,
            InvitationRefusalReason::UnknownApp,
            InvitationRefusalReason::ReturnToMalformed,
            InvitationRefusalReason::ReturnToNotRegistered => 422,
        };

        return $this->refuse($refused->reason->value, $refused->getMessage(), $status);
    }

    private function notOfferedMessage(Role $role): string
    {
        return $role->tenant_assignable
            ? "The role [{$role->name}] is not one this organization can use."
            : "The role [{$role->name}] is a staff role. Staff roles are never granted by invitation — grant it to the member after they join.";
    }

    private function appName(?string $clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $name = Client::query()->where('client_id', $clientId)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSent(SentInvitation $sent): array
    {
        $invitation = $sent->invitation;

        return InvitationResource::from(
            $invitation,
            $this->contexts($invitation->organization_id, [$invitation->id])[$invitation->id] ?? null,
            $this->parkedRoles($invitation->organization_id, [$invitation->id])[$invitation->id] ?? [],
        );
    }

    /**
     * @param  Collection<int, Invitation>  $rows
     */
    private function presentPage(string $organizationId, Collection $rows, int $limit): JsonResponse
    {
        $ids = array_values(array_map(static fn (Invitation $i): string => $i->id, $rows->take($limit)->all()));
        $contexts = $this->contexts($organizationId, $ids);
        $roles = $this->parkedRoles($organizationId, $ids);

        return $this->page($rows, $limit, fn (Invitation $i): array => InvitationResource::from($i, $contexts[$i->id] ?? null, $roles[$i->id] ?? []));
    }

    /**
     * @param  list<string>  $invitationIds
     * @return array<string, InvitationContext>
     */
    private function contexts(string $organizationId, array $invitationIds): array
    {
        $out = [];

        foreach (InvitationContext::query()->where('organization_id', $organizationId)->whereIn('invitation_id', $invitationIds)->get() as $context) {
            $out[$context->invitation_id] = $context;
        }

        return $out;
    }

    /**
     * @param  list<string>  $invitationIds
     * @return array<string, list<string>>
     */
    private function parkedRoles(string $organizationId, array $invitationIds): array
    {
        $out = [];

        foreach (InvitationRoleGrant::query()->where('organization_id', $organizationId)->whereIn('invitation_id', $invitationIds)->orderBy('role_id')->get(['invitation_id', 'role_id']) as $grant) {
            $invitationId = $grant->getAttribute('invitation_id');

            if (is_string($invitationId)) {
                $out[$invitationId][] = $grant->role_id;
            }
        }

        return $out;
    }
}
