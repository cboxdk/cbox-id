<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Http\Resources\Environment\InvitationResource;
use App\Models\InvitationContext;
use App\Models\InvitationRoleGrant;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Shared lookups and the wire shape for the invitation actions. A helper, not an action.
 */
final class InvitationFields
{
    /**
     * Any invitation with this id IN this organization, whatever its state — the
     * organization bound in the query, so another organization's invitation named under
     * this one is not found and is never touched.
     *
     * @throws ActionRefused
     */
    public static function find(string $organizationId, string $invitationId): Invitation
    {
        return Invitation::query()
            ->whereKey($invitationId)
            ->where('organization_id', $organizationId)
            ->first() ?? throw ActionRefused::notFound('invitation');
    }

    /**
     * Who the mail says the invitation is from.
     *
     * A person signs it with their own name, and the trail keys it on their subject id. A
     * key is nobody: it signs with the name it was given, else the app the invitation leads
     * back to, else the environment's name.
     *
     * WHERE THE PERSON IS A SUBJECT depends on the door. On the organization console they
     * are the signed-in subject of THIS environment ({@see CurrentUser}); an environment
     * console's administrator is a person of the platform root, looked up there. Asking the
     * platform root for an organization member found nobody, and the mail said "An
     * administrator". Through a token they signed in for, the token names them.
     */
    public static function inviter(ActionContext $context, ?string $name = null, ?string $clientId = null): Inviter
    {
        $principal = $context->principal;

        if ($principal instanceof DelegatedTokenPrincipal) {
            return new Inviter($principal->subjectId(), $principal->personName());
        }

        if ($principal instanceof ConsoleSessionPrincipal && $principal->confinedToOrganization() !== null && app(CurrentUser::class)->check()) {
            $me = app(CurrentUser::class);

            return new Inviter($me->id(), $me->name());
        }

        if ($context->principal instanceof ConsoleSessionPrincipal) {
            $subjectId = $context->principal->scope()->actorId();
            $subject = $subjectId === '' ? null : app(PlatformRoot::class)->run(
                static fn () => app(Subjects::class)->find($subjectId),
            );

            return new Inviter(
                $subjectId === '' ? null : $subjectId,
                $subject === null ? 'An administrator' : ($subject->name ?? $subject->email ?? 'An administrator'),
            );
        }

        return new Inviter(null, $name ?? self::appName($clientId) ?? app(CurrentEnvironment::class)->name() ?? 'Your team');
    }

    /**
     * An invitation service refusal, with the status the management API has always answered
     * it with.
     */
    public static function refused(InvitationRefused $refused): ActionRefused
    {
        $status = match ($refused->reason) {
            InvitationRefusalReason::AlreadyMember,
            InvitationRefusalReason::AccessRoleConflict,
            InvitationRefusalReason::NotPending => 409,
            InvitationRefusalReason::TooSoon => 429,
            InvitationRefusalReason::MailFailed => 503,
            InvitationRefusalReason::RoleNotOffered,
            InvitationRefusalReason::AccessRoleNotOffered,
            InvitationRefusalReason::ReturnWithoutApp,
            InvitationRefusalReason::UnknownApp,
            InvitationRefusalReason::ReturnToMalformed,
            InvitationRefusalReason::ReturnToNotRegistered => 422,
        };

        $field = match ($refused->reason) {
            InvitationRefusalReason::AccessRoleNotOffered, InvitationRefusalReason::AccessRoleConflict => 'roles',
            InvitationRefusalReason::NotPending, InvitationRefusalReason::TooSoon => 'invitation_id',
            default => $refused->field(),
        };

        return new ActionRefused($refused->reason->value, $refused->getMessage(), $status, $field);
    }

    /**
     * `Invitation` in the spec, with the app it leads back to and the access roles parked
     * for it.
     *
     * @return array<string, mixed>
     */
    public static function present(Invitation $invitation): array
    {
        return self::presentMany($invitation->organization_id, [$invitation])[0];
    }

    /**
     * @param  list<Invitation>  $invitations  All in one organization.
     * @return list<array<string, mixed>>
     */
    public static function presentMany(string $organizationId, array $invitations): array
    {
        $ids = array_map(static fn (Invitation $invitation): string => $invitation->id, $invitations);

        $contexts = [];

        foreach (InvitationContext::query()->where('organization_id', $organizationId)->whereIn('invitation_id', $ids)->get() as $context) {
            $contexts[$context->invitation_id] = $context;
        }

        $roles = [];

        foreach (InvitationRoleGrant::query()->where('organization_id', $organizationId)->whereIn('invitation_id', $ids)->orderBy('role_id')->get(['invitation_id', 'role_id']) as $grant) {
            $invitationId = $grant->getAttribute('invitation_id');

            if (is_string($invitationId)) {
                $roles[$invitationId][] = $grant->role_id;
            }
        }

        return array_map(
            static fn (Invitation $invitation): array => InvitationResource::from($invitation, $contexts[$invitation->id] ?? null, $roles[$invitation->id] ?? []),
            $invitations,
        );
    }

    private static function appName(?string $clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $name = Client::query()->where('client_id', $clientId)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
