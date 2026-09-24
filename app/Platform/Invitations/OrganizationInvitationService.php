<?php

declare(strict_types=1);

namespace App\Platform\Invitations;

use App\Mail\InvitationMail;
use App\Models\InvitationContext;
use App\Models\InvitationRoleGrant;
use App\Platform\GrantAccessRole;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\AcceptedInvitation;
use App\Platform\Invitations\ValueObjects\InvitationPreview;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Invitations\ValueObjects\ReturnTarget;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use App\Platform\MailLinks;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use App\Platform\SodGuard;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\InvalidInvitation;
use Cbox\Id\Organization\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * {@see OrganizationInvitations}, over the framework's {@see Invitations} and the two app
 * tables that annotate an invitation: the access roles parked for it
 * ({@see InvitationRoleGrant}) and the app it came from ({@see InvitationContext}).
 *
 * Runs in whatever environment scope the caller stands in — the tenant's own console, or an
 * environment administrator's — which is the environment the organization lives in. Nothing
 * here switches scope; a caller that needed to would be inviting into the wrong realm.
 */
final readonly class OrganizationInvitationService implements OrganizationInvitations
{
    /**
     * How long a re-send is refused after one went. The mailer is synchronous and the sending
     * domain is shared by every tenant, so a held-down button is an outbound flood billed to
     * everybody's reputation.
     */
    private const RESEND_WINDOW_SECONDS = 60;

    public function __construct(
        private Invitations $invitations,
        private Memberships $memberships,
        private Subjects $subjects,
        private Organizations $organizations,
        private OrgAccessRoles $catalog,
        private SodGuard $sod,
        private GrantAccessRole $access,
        private AppReturnTargets $targets,
        private MailLinks $links,
        private AuditLog $audit,
    ) {}

    public function send(NewInvitation $invitation): SentInvitation
    {
        $organizationId = $invitation->organizationId;
        $email = trim($invitation->email);

        // Ownership is TRANSFERRED. An invitation that could mint a second owner is a way
        // round the single-owner rule that nobody has to press "Transfer ownership" for.
        if (! in_array($invitation->role, OrgRoles::assignable(), true)) {
            throw InvitationRefused::roleNotOffered();
        }

        /*
         * Somebody already on THIS roster. Asked of this organization only: the subject
         * lookup is environment-wide, and answering "that address has an account" about
         * a person who is not a member here would let an administrator probe addresses.
         */
        $existing = $this->subjects->findByEmail($email);

        if ($existing !== null && $this->memberships->of($organizationId, $existing->id) !== null) {
            throw InvitationRefused::alreadyMember();
        }

        // Claims until checked: every role must be genuinely assignable HERE, and the SET is
        // checked for segregation of duties now, where there is a form to report into —
        // by acceptance time the only place left to refuse is a redirect.
        $roleIds = $this->offeredOrRefuse($organizationId, $invitation->accessRoleIds);
        $conflict = $this->sod->refuseSet($organizationId, $roleIds);

        if ($conflict !== null) {
            throw InvitationRefused::accessRoleConflict($conflict->message());
        }

        $target = $this->targets->resolve($organizationId, $invitation->clientId, $invitation->returnTo);

        // `invite()` revokes the address's earlier pending invitation as it mints this one,
        // so what was parked for THAT one is forgotten here, in the same transaction.
        $superseded = $this->pendingIdsFor($organizationId, $email);

        $pending = DB::transaction(function () use ($invitation, $organizationId, $email, $roleIds, $target, $superseded) {
            $pending = $this->invitations->invite($organizationId, $email, $invitation->role, $invitation->inviter->subjectId);

            $this->forget($organizationId, $superseded);
            $this->park($pending->invitation, $roleIds, $target, $invitation->inviter);

            return $pending;
        });

        /*
         * A TRANSPORT FAILURE IS NOT A SUCCESSFUL INVITE. The row is committed by the time
         * the mailer runs, so an SMTP outage used to leave an invitation nobody received —
         * and inviting the same address again then superseded it silently. Withdrawn, so the
         * obvious next step (try again) works and the screen tells the truth.
         */
        try {
            $this->mail($pending->invitation, $pending->token, $invitation->inviter, $target);
        } catch (Throwable $e) {
            $this->invitations->revoke($organizationId, $pending->invitation->id);
            $this->forget($organizationId, [$pending->invitation->id]);

            report($e);

            throw InvitationRefused::mailFailed(invitationKept: false);
        }

        return new SentInvitation($pending->invitation, $target);
    }

    public function resend(string $organizationId, string $invitationId, Inviter $inviter): SentInvitation
    {
        $found = $this->findPending($organizationId, $invitationId) ?? throw InvitationRefused::notPending();

        $key = 'organization-invite-resend|'.$organizationId.'|'.$found->email;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw InvitationRefused::tooSoon(RateLimiter::availableIn($key));
        }

        $context = $this->contextFor($organizationId, $found->id);
        $target = $context === null ? null : $this->targets->revalidate($organizationId, $context->client_id, $context->return_to);

        /*
         * A NEW TOKEN, not the old one: the server stores only its hash, so there is nothing
         * to re-send — and the reason somebody asks is usually that the first link expired.
         * The role is re-issued as it was, except that an invitation minted before ownership
         * became a transfer is re-issued as Admin rather than as a second owner.
         */
        $role = in_array($found->role, OrgRoles::assignable(), true) ? $found->role : MembershipRole::Admin;

        $pending = DB::transaction(function () use ($organizationId, $found, $role, $inviter) {
            $pending = $this->invitations->invite($organizationId, $found->email, $role, $inviter->subjectId);

            // What the old link carried moves to the new one, so re-sending cannot quietly
            // drop the access roles or the way back to the app.
            InvitationRoleGrant::query()
                ->where('organization_id', $organizationId)
                ->where('invitation_id', $found->id)
                ->update(['invitation_id' => $pending->invitation->id]);

            InvitationContext::query()
                ->where('organization_id', $organizationId)
                ->where('invitation_id', $found->id)
                ->update(['invitation_id' => $pending->invitation->id, 'invited_by_name' => $inviter->name]);

            return $pending;
        });

        try {
            $this->mail($pending->invitation, $pending->token, $inviter, $target);
        } catch (Throwable $e) {
            /*
             * THE INVITATION STAYS. The person already had one, and destroying the
             * replacement on a transport failure would leave them with none at all. A live
             * invitation nobody received is still on the list, and the button that failed is
             * the one that retries it.
             */
            report($e);

            throw InvitationRefused::mailFailed(invitationKept: true);
        }

        // Charged AFTER a successful send: charging on the way in answered the retry of a
        // failed send with "Already sent", about a mail that never went.
        RateLimiter::hit($key, self::RESEND_WINDOW_SECONDS);

        $this->record('organization.invitation_resent', $organizationId, $inviter->subjectId, $pending->invitation->id, [
            'email' => $found->email,
            'role' => $role->value,
        ]);

        return new SentInvitation($pending->invitation, $target);
    }

    public function revoke(string $organizationId, string $invitationId, ?string $actorId): void
    {
        $found = $this->findPending($organizationId, $invitationId) ?? throw InvitationRefused::notPending();

        $this->invitations->revoke($organizationId, $found->id);

        // AND WHAT IT PARKED. Revoking used to update the invitation and leave its roles
        // behind — keyed by invitation id with no organization in the predicate, so the
        // cleanup could also be pointed at somebody else's invitation.
        $this->forget($organizationId, [$found->id]);

        $this->record('organization.invitation_revoked', $organizationId, $actorId, $found->id, ['email' => $found->email]);
    }

    public function pending(string $organizationId, int $limit = 25): array
    {
        $rows = $this->invitations->pending($organizationId, $limit);

        if ($rows->isEmpty()) {
            return [];
        }

        $contexts = InvitationContext::query()
            ->where('organization_id', $organizationId)
            ->whereIn('invitation_id', $rows->modelKeys())
            ->get()
            ->keyBy('invitation_id');

        $appNames = $this->appNames(array_values(array_filter(
            $contexts->pluck('client_id')->all(),
            'is_string',
        )));

        // Invitations sent before the context table existed carry only an inviter id.
        $legacyInviters = $this->subjects->findMany(array_values(array_filter(
            $rows->filter(fn (Invitation $row): bool => ! $contexts->has($row->id))->pluck('invited_by')->all(),
            'is_string',
        )));

        $out = [];

        foreach ($rows as $row) {
            $context = $contexts->get($row->id);
            $legacy = is_string($row->invited_by) ? ($legacyInviters[$row->invited_by] ?? null) : null;
            $invitedAt = $row->getAttribute('created_at');

            $out[] = new PendingInvitationSummary(
                id: $row->id,
                email: $row->email,
                role: $row->role,
                expiresAt: $row->expires_at,
                invitedAt: $invitedAt instanceof CarbonInterface ? $invitedAt : null,
                inviterName: $context->invited_by_name ?? $legacy->name ?? $legacy->email ?? null,
                appName: $context?->client_id === null ? null : ($appNames[$context->client_id] ?? null),
            );
        }

        return $out;
    }

    public function preview(string $token): ?InvitationPreview
    {
        $invitation = $this->invitations->byToken($token);

        // `byToken()` is a lookup by hash, not a validity check: it resolves a spent or
        // withdrawn invitation as happily as a live one.
        if ($invitation === null || ! $invitation->isPending()) {
            return null;
        }

        $context = $this->contextFor($invitation->organization_id, $invitation->id);
        $inviter = $context?->invited_by_name;

        if ($inviter === null && is_string($invitation->invited_by)) {
            $subject = $this->subjects->find($invitation->invited_by);
            $inviter = $subject->name ?? $subject->email ?? null;
        }

        $target = $context === null ? null : $this->targets->revalidate(
            $invitation->organization_id,
            $context->client_id,
            $context->return_to,
        );

        return new InvitationPreview(
            email: $invitation->email,
            organizationName: $this->organizations->find($invitation->organization_id)->name ?? 'the organization',
            role: $invitation->role,
            inviterName: $inviter,
            appName: $target?->appName,
        );
    }

    public function accept(string $token): AcceptedInvitation
    {
        $invitation = $this->invitations->byToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw InvalidInvitation::make();
        }

        $subject = $this->subjects->findByEmail($invitation->email) ?? $this->subjects->create($invitation->email);

        // Single-use by the token itself, under a row lock: a replayed or racing accept
        // redeems nothing and throws.
        $membership = $this->invitations->accept($token, $subject->id);

        // Read BEFORE the context is forgotten, and re-validated rather than trusted: the
        // app's registrations may have changed in the week this invitation was live.
        $context = $this->contextFor($invitation->organization_id, $invitation->id);
        $target = $context === null ? null : $this->targets->revalidate(
            $invitation->organization_id,
            $context->client_id,
            $context->return_to,
        );

        $withheld = $this->grantParkedRoles($invitation, $subject->id);

        $this->forget($invitation->organization_id, [$invitation->id]);

        return new AcceptedInvitation($membership, $subject->id, $target, $withheld);
    }

    /**
     * Apply the access roles chosen for THIS invitation.
     *
     * THROUGH THE GATE, and a refusal is skipped rather than fatal. Segregation-of-duties
     * policies can be defined between the invite and its acceptance, and a role can be
     * retired in between — an app ships a manifest without it. Uncaught, either was not a
     * 500 but a permanent one: the acceptance above has already committed, so the invitation
     * is spent, the person is a member holding an arbitrary prefix of their roles, and every
     * retry says the invitation is invalid. The person still joins; what was withheld goes
     * on the organization's own trail, where the administrator who needs to know can see it.
     *
     * @return list<string> the role ids that were withheld
     */
    private function grantParkedRoles(Invitation $invitation, string $subjectId): array
    {
        $withheld = [];

        $grants = InvitationRoleGrant::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('invitation_id', $invitation->id)
            ->get();

        foreach ($grants as $grant) {
            try {
                // As the tenant: a role made staff-only since the invitation went is withheld
                // here like any other refusal, rather than granted on stale say-so.
                if ($this->access->grantAsTenant($invitation->organization_id, $subjectId, $grant->role_id, GrantSource::Manual) === null) {
                    continue;
                }
            } catch (UnknownRole|GrantRefused) {
                // Retired or deleted since the invitation went — withheld like a conflict.
            }

            $withheld[] = $grant->role_id;
        }

        if ($withheld !== []) {
            $this->audit->record(new AuditEvent(
                action: 'role.grant_withheld',
                organizationId: $invitation->organization_id,
                actorType: ActorType::System,
                targetType: 'user',
                targetId: $subjectId,
                context: ['organization_id' => $invitation->organization_id, 'role_ids' => $withheld, 'reason' => 'refused when the invitation was accepted'],
            ));
        }

        return $withheld;
    }

    /**
     * @param  list<string>  $roleIds
     */
    private function park(Invitation $invitation, array $roleIds, ?ReturnTarget $target, Inviter $inviter): void
    {
        foreach ($roleIds as $roleId) {
            InvitationRoleGrant::query()->firstOrCreate([
                'invitation_id' => $invitation->id,
                'role_id' => $roleId,
            ], [
                'organization_id' => $invitation->organization_id,
                'email' => $invitation->email,
            ]);
        }

        InvitationContext::query()->create([
            'organization_id' => $invitation->organization_id,
            'invitation_id' => $invitation->id,
            'client_id' => $target?->clientId,
            'return_to' => $target?->url,
            'invited_by_name' => mb_substr($inviter->name, 0, 190),
        ]);
    }

    /**
     * Drop what was parked for these invitations — always WITHIN the organization, so an
     * invitation id from elsewhere matches nothing.
     *
     * @param  list<string>  $invitationIds
     */
    private function forget(string $organizationId, array $invitationIds): void
    {
        if ($invitationIds === []) {
            return;
        }

        InvitationRoleGrant::query()
            ->where('organization_id', $organizationId)
            ->whereIn('invitation_id', $invitationIds)
            ->delete();

        InvitationContext::query()
            ->where('organization_id', $organizationId)
            ->whereIn('invitation_id', $invitationIds)
            ->delete();
    }

    private function mail(Invitation $invitation, string $token, Inviter $inviter, ?ReturnTarget $target): void
    {
        Mail::to($invitation->email)->send(new InvitationMail(
            organization: $this->organizations->find($invitation->organization_id)->name ?? 'your team',
            inviter: $inviter->name,
            // MailLinks, not route(): a mailed link's origin comes from the deployment, not
            // from the Host header of whoever asked to send it.
            url: $this->links->route('invitation.accept', $token),
            role: $invitation->role->label(),
            app: $target?->appName,
        ));
    }

    /** The pending invitation with this id IN this organization — bound in the WHERE clause. */
    private function findPending(string $organizationId, string $invitationId): ?Invitation
    {
        return Invitation::query()
            ->whereKey($invitationId)
            ->where('organization_id', $organizationId)
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->first();
    }

    private function contextFor(string $organizationId, string $invitationId): ?InvitationContext
    {
        return InvitationContext::query()
            ->where('organization_id', $organizationId)
            ->where('invitation_id', $invitationId)
            ->first();
    }

    /**
     * @return list<string>
     */
    private function pendingIdsFor(string $organizationId, string $email): array
    {
        return array_values(array_filter(
            Invitation::query()
                ->where('organization_id', $organizationId)
                ->where('email', $email)
                ->where('status', InvitationStatus::Pending->value)
                ->pluck('id')
                ->all(),
            'is_string',
        ));
    }

    /**
     * The requested roles, each one checked against what this organization offers.
     *
     * REFUSED, NOT FILTERED. Dropping the roles that did not qualify sent the invitation
     * anyway and said "Invitation sent" — so a crafted id was refused in silence, and the
     * administrator was told the invitation carried what they asked for when it did not.
     *
     * @param  list<string>  $roleIds
     * @return list<string>
     *
     * @throws InvitationRefused
     */
    private function offeredOrRefuse(string $organizationId, array $roleIds): array
    {
        $roleIds = array_values(array_unique($roleIds));

        if ($roleIds === []) {
            return [];
        }

        // The TENANT plane's set, whichever console sends the invitation: accepting it is
        // the invitee's act inside their organization, and a staff-only role never rides
        // in on one. An environment administrator grants staff rights on the person once
        // they have joined.
        $assignable = array_filter($this->catalog->tenantAssignable($organizationId)->pluck('id')->all(), 'is_string');

        if (array_diff($roleIds, $assignable) !== []) {
            throw InvitationRefused::accessRoleNotOffered();
        }

        return $roleIds;
    }

    /**
     * @param  list<string>  $clientIds
     * @return array<string, string>
     */
    private function appNames(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $names = [];

        foreach (Client::query()->whereIn('client_id', array_values(array_unique($clientIds)))->get(['client_id', 'name']) as $client) {
            $names[$client->client_id] = $client->name;
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, string $organizationId, ?string $actorId, string $invitationId, array $context): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::User,
            actorId: $actorId,
            organizationId: $organizationId,
            targetType: 'invitation',
            targetId: $invitationId,
            context: $context,
        ));
    }
}
