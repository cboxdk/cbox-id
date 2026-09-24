<?php

declare(strict_types=1);

namespace App\Platform\Invitations;

use App\Mail\OrganizationInviteMail;
use App\Models\InvitationContext;
use App\Platform\Invitations\Contracts\TeamInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\InvitationPreview;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\MailLinks;
use App\Platform\OrganizationActivity;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Platform\PlatformRoot;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Throwable;

/**
 * {@see TeamInvitations}, over the framework's {@see Invitations}, IN THE PLATFORM ROOT.
 *
 * A workspace and its team live in the root whatever host the request arrived on, so every
 * read and write here runs there — stated once, here, rather than at each door.
 *
 * The inviter's NAME is kept beside the invitation ({@see InvitationContext}, with no app):
 * a management key is nobody a subject lookup can name, and the invitee should read the
 * same "Deploy bot invited you" on the page they land on as in the mail that sent them.
 */
final readonly class TeamInvitationService implements TeamInvitations
{
    /** How long the accept link lives. */
    private const LINK_DAYS = 7;

    /**
     * How long a re-send is refused after one went. The mailer is synchronous and the sending
     * domain is shared with every tenant, so a held-down button is an outbound flood.
     */
    private const RESEND_WINDOW_SECONDS = 60;

    public function __construct(
        private Invitations $invitations,
        private Memberships $memberships,
        private Subjects $subjects,
        private Organizations $organizations,
        private OrganizationActivity $activity,
        private MailLinks $links,
        private PlatformRoot $root,
    ) {}

    public function send(string $organizationId, string $email, MembershipRole $role, Inviter $inviter, AuditActor $actor): Invitation
    {
        $email = trim($email);

        // Ownership is transferred, never granted: an invitation that could mint an owner
        // would hand the workspace away without its owner doing anything.
        if (! in_array($role, MembershipRole::assignable(), true)) {
            throw InvitationRefused::teamRoleNotOffered();
        }

        $invitation = $this->inRoot(function () use ($organizationId, $email, $role, $inviter): Invitation {
            /*
             * Somebody already on THIS team. One message whether or not the address has an
             * account elsewhere: the subject lookup is global, and "that address has an
             * account" about somebody who is not on this team would let an administrator
             * probe addresses.
             */
            $existing = $this->subjects->findByEmail($email);

            if ($existing !== null && $this->memberships->of($organizationId, $existing->id) !== null) {
                throw InvitationRefused::alreadyOnTeam();
            }

            // `invite()` supersedes the address's earlier pending invitation as it mints
            // this one, so the name kept for THAT one is forgotten in the same transaction.
            $superseded = $this->pendingIdsFor($organizationId, $email);

            $pending = DB::transaction(function () use ($organizationId, $email, $role, $inviter, $superseded) {
                $pending = $this->invitations->invite($organizationId, $email, $role, $inviter->subjectId);

                $this->forget($organizationId, $superseded);
                $this->remember($pending->invitation, $inviter);

                return $pending;
            });

            // After the commit, never inside it: a mail server holding the transaction open
            // holds the invitation rows' locks with it.
            $this->mailOrWithdraw($pending->invitation, $pending->token, $inviter);

            return $pending->invitation;
        });

        $this->activity->record($organizationId, 'organization.member_invited', $actor->id,
            targetType: 'invitation', targetId: $invitation->id,
            context: ['email' => $email, 'role' => $role->value],
            request: request(), actorType: $actor->type);

        return $invitation;
    }

    public function resend(string $organizationId, string $invitationId, Inviter $inviter, AuditActor $actor): Invitation
    {
        $found = $this->readInRoot(fn (): ?Invitation => $this->findPending($organizationId, $invitationId), null)
            ?? throw InvitationRefused::notPending();

        $key = 'organization-invite-resend|'.$organizationId.'|'.$found->email;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw InvitationRefused::tooSoon(RateLimiter::availableIn($key));
        }

        // An invitation minted before Owner stopped being grantable is re-issued as Admin.
        $role = in_array($found->role, MembershipRole::assignable(), true) ? $found->role : MembershipRole::Admin;

        $invitation = $this->inRoot(function () use ($organizationId, $found, $role, $inviter): Invitation {
            /*
             * A NEW TOKEN, not the old one: the server stores only its hash, so there is
             * nothing to re-send — and the reason somebody asks is usually that the first
             * link expired. NO PRE-REVOKE: `invite()` supersedes the earlier one, and
             * revoking first left the person with nothing when the mail then failed.
             */
            $pending = DB::transaction(function () use ($organizationId, $found, $role, $inviter) {
                $pending = $this->invitations->invite($organizationId, $found->email, $role, $inviter->subjectId);

                $this->forget($organizationId, [$found->id]);
                $this->remember($pending->invitation, $inviter);

                return $pending;
            });

            try {
                $this->mail($pending->invitation, $pending->token, $inviter);
            } catch (Throwable $e) {
                /*
                 * THE INVITATION STAYS. The person already had one; destroying the
                 * replacement on a transport failure would leave them with none. It is
                 * still listed, and the button that failed is the one that retries it.
                 */
                report($e);

                throw InvitationRefused::mailFailed(invitationKept: true);
            }

            return $pending->invitation;
        });

        // Charged AFTER a successful send: charging on the way in answered the retry of a
        // failed send with "Already sent", about a mail that never went.
        RateLimiter::hit($key, self::RESEND_WINDOW_SECONDS);

        $this->activity->record($organizationId, 'organization.member_invited', $actor->id,
            targetType: 'invitation', targetId: $invitation->id,
            context: ['email' => $found->email, 'role' => $role->value, 'resent' => true],
            request: request(), actorType: $actor->type);

        return $invitation;
    }

    public function revoke(string $organizationId, string $invitationId, AuditActor $actor): void
    {
        $found = $this->inRoot(function () use ($organizationId, $invitationId, $actor): Invitation {
            $found = $this->findPending($organizationId, $invitationId) ?? throw InvitationRefused::notPending();

            $this->invitations->revoke($organizationId, $found->id, $actor->id);
            $this->forget($organizationId, [$found->id]);

            return $found;
        });

        $this->activity->record($organizationId, 'organization.invitation_revoked', $actor->id,
            targetType: 'invitation', targetId: $found->id,
            context: ['email' => $found->email],
            request: request(), actorType: $actor->type);
    }

    public function pending(string $organizationId, int $limit = 25): array
    {
        return $this->readInRoot(function () use ($organizationId, $limit): array {
            $rows = $this->invitations->pending($organizationId, $limit);

            if ($rows->isEmpty()) {
                return [];
            }

            $names = InvitationContext::query()
                ->where('organization_id', $organizationId)
                ->whereIn('invitation_id', $rows->modelKeys())
                ->pluck('invited_by_name', 'invitation_id');

            // Invitations sent before the name was kept carry only an inviter id.
            $people = $this->subjects->findMany(array_values(array_filter(
                $rows->filter(fn (Invitation $row): bool => ! is_string($names->get($row->id)))->pluck('invited_by')->all(),
                'is_string',
            )));

            $out = [];

            foreach ($rows as $row) {
                $person = is_string($row->invited_by) ? ($people[$row->invited_by] ?? null) : null;
                $name = $names->get($row->id);
                $invitedAt = $row->getAttribute('created_at');

                $out[] = new PendingInvitationSummary(
                    id: $row->id,
                    email: $row->email,
                    role: $row->role,
                    expiresAt: $row->expires_at,
                    invitedAt: $invitedAt instanceof CarbonInterface ? $invitedAt : null,
                    inviterName: is_string($name) ? $name : ($person->name ?? $person->email ?? null),
                );
            }

            return $out;
        }, []);
    }

    public function countPending(string $organizationId): int
    {
        return $this->readInRoot(fn (): int => $this->invitations->countPending($organizationId), 0);
    }

    public function preview(string $token): ?InvitationPreview
    {
        return $this->readInRoot(function () use ($token): ?InvitationPreview {
            $invitation = $this->invitations->byToken($token);

            // `byToken()` is a lookup by hash, not a validity check: it resolves a spent or
            // withdrawn invitation as happily as a live one.
            if ($invitation === null || ! $invitation->isPending()) {
                return null;
            }

            $name = InvitationContext::query()
                ->where('organization_id', $invitation->organization_id)
                ->where('invitation_id', $invitation->id)
                ->value('invited_by_name');

            if (! is_string($name) && is_string($invitation->invited_by)) {
                $person = $this->subjects->find($invitation->invited_by);
                $name = $person->name ?? $person->email ?? null;
            }

            return new InvitationPreview(
                email: $invitation->email,
                organizationName: $this->organizations->find($invitation->organization_id)->name ?? 'the workspace',
                role: $invitation->role,
                inviterName: is_string($name) ? $name : null,
            );
        }, null);
    }

    /**
     * Mail the link; on a transport failure, withdraw what was just created and refuse.
     *
     * A TRANSPORT FAILURE IS NOT A SUCCESSFUL INVITE. The console used to 500 while the row
     * sat in the database, and the API answered 201 about a mail nobody got. Withdrawn, so
     * the obvious next step (try again) works and the answer tells the truth.
     */
    private function mailOrWithdraw(Invitation $invitation, string $token, Inviter $inviter): void
    {
        try {
            $this->mail($invitation, $token, $inviter);
        } catch (Throwable $e) {
            $this->invitations->revoke($invitation->organization_id, $invitation->id);
            $this->forget($invitation->organization_id, [$invitation->id]);

            report($e);

            throw InvitationRefused::mailFailed(invitationKept: false);
        }
    }

    private function mail(Invitation $invitation, string $token, Inviter $inviter): void
    {
        Mail::to($invitation->email)->send(new OrganizationInviteMail(
            organization: $this->organizations->find($invitation->organization_id)->name ?? 'your workspace',
            inviter: $inviter->name,
            // MailLinks, not URL::: a mailed link's origin comes from the deployment, not
            // from the Host header of whoever asked to send it — browser or API caller.
            url: $this->links->temporarySignedRoute('organization.invite.accept', now()->addDays(self::LINK_DAYS), ['token' => $token]),
            role: $invitation->role->label(),
        ));
    }

    private function remember(Invitation $invitation, Inviter $inviter): void
    {
        InvitationContext::query()->create([
            'organization_id' => $invitation->organization_id,
            'invitation_id' => $invitation->id,
            'client_id' => null,
            'return_to' => null,
            'invited_by_name' => $inviter->name,
        ]);
    }

    /**
     * @param  list<string>  $invitationIds
     */
    private function forget(string $organizationId, array $invitationIds): void
    {
        if ($invitationIds === []) {
            return;
        }

        InvitationContext::query()
            ->where('organization_id', $organizationId)
            ->whereIn('invitation_id', $invitationIds)
            ->delete();
    }

    /** The pending invitation with this id ON this team — bound in the WHERE clause. */
    private function findPending(string $organizationId, string $invitationId): ?Invitation
    {
        return Invitation::query()
            ->whereKey($invitationId)
            ->where('organization_id', $organizationId)
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * @return list<string>
     */
    private function pendingIdsFor(string $organizationId, string $email): array
    {
        return array_values(array_filter(Invitation::query()
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending->value)
            ->pluck('id')
            ->all(), 'is_string'));
    }

    /**
     * Run a WRITE in the platform root, where every workspace and its team live.
     *
     * A deployment with no platform root has no workspace to invite anybody onto, and only a
     * caller already holding a workspace id reaches here — so it is refused rather than run
     * in whatever environment happens to be current.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inRoot(Closure $callback): mixed
    {
        $result = $this->root->run(static fn (): array => [$callback()]);

        if ($result === null) {
            throw new LogicException('There is no platform root, so there is no workspace team to invite onto.');
        }

        return $result[0];
    }

    /**
     * A READ in the platform root; with no root there is nothing to read.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $otherwise
     * @return T
     */
    private function readInRoot(Closure $callback, mixed $otherwise): mixed
    {
        $result = $this->root->run(static fn (): array => [$callback()]);

        return $result === null ? $otherwise : $result[0];
    }
}
