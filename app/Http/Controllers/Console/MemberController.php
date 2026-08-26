<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\InviteMemberRequest;
use App\Http\Requests\Console\SetEnvironmentAccessRequest;
use App\Mail\OrganizationInviteMail;
use App\Platform\MailLinks;
use App\Platform\OrganizationActivity;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;
use Throwable;

/**
 * IDENTITY PLATFORM › ADMINISTRATORS — the account's team, their roles, the environments
 * they reach, and the invitations nobody has accepted yet.
 *
 * IDENTITY AND AUTHORITY ARE TWO ROWS. A member is a {@see Membership} for what they may
 * do and a subject for who they are, which is why the roster hydrates subjects alongside
 * the memberships rather than reading names off them.
 *
 * EVERY WRITE RE-RESOLVES ITS TARGET THROUGH {@see self::resolve()}, with the organization
 * id IN THE QUERY rather than compared afterwards. A membership id off the wire is exactly
 * the thing that must not be taken on trust, and a comparison after the fact is one
 * refactor away from being dropped — which is the shape that once shipped a
 * cross-organization IDOR on `/governance/{campaign}`.
 */
final readonly class MemberController extends ConsoleController
{
    /**
     * Rows per page — 25, like every other list in this console. It is the number that
     * turns this page from one whose cost grows with the organization into one that
     * does not.
     */
    private const PER_PAGE = 25;

    /**
     * How many environment checkboxes the access editor draws at once. Past this a list
     * of checkboxes stops being a way to choose and starts being a wall; the search box
     * is how you reach the rest.
     */
    private const ENVIRONMENTS_PER_EDITOR = 25;

    /** Pending invitations shown before the panel says how many more there are. */
    private const INVITATIONS_SHOWN = 25;

    public function index(
        Request $request,
        Memberships $members,
        Subjects $subjects,
        Invitations $invitations,
    ): Response|RedirectResponse {
        // The roster is PII — a Developer or billing-only role may not read it. Sent
        // somewhere they can be rather than refused: that is the console's own answer,
        // and the one the navigation-honesty test holds.
        if ($this->scope->capabilities()?->canReadMembers() !== true) {
            return to_route('projects');
        }

        $organizationId = $this->scope->organizationId();

        /*
         * PAGINATED, and it always should have been. The roster used to hydrate whole and
         * ask two more questions per row — measured at 10 queries and ~13 KB per member,
         * so a 101-member organization served a 1.3 MB document off 1037 queries.
         *
         * IN THE PLATFORM ROOT, like every membership read on this page: the management
         * plane's rows live in the root whether or not the console host resolves to it,
         * so the scope is stated rather than assumed.
         *
         * @var LengthAwarePaginator<int, Membership> $roster
         */
        $roster = $organizationId === null
            ? new Paginator([], 0, self::PER_PAGE)
            : (app(PlatformRoot::class)->run(
                fn (): LengthAwarePaginator => $members->paginateForOrganization($organizationId, self::PER_PAGE),
            ) ?? new Paginator([], 0, self::PER_PAGE));

        /** @var list<string> $userIds */
        $userIds = collect($roster->items())->pluck('user_id')->all();

        // The people behind the memberships, in ONE query. A membership carries authority
        // and not identity, so every name and address here is a second lookup — and doing
        // it inside the loop is how a 25-row roster becomes 25 queries.
        $people = $userIds === [] ? [] : (app(PlatformRoot::class)->run(
            fn (): array => $subjects->findMany($userIds),
        ) ?? []);

        // And the environment access per row, also in one pass. What the organization owns
        // is a property of the ORGANIZATION rather than of each member.
        $accessByUser = ($organizationId === null || $userIds === []) ? [] : (app(PlatformRoot::class)->run(
            fn (): array => $members->accessibleEnvironmentIdsFor($organizationId, $userIds),
        ) ?? []);

        // Non-nullable here: the read gate above returned for anybody without capabilities.
        $canManage = $this->scope->capabilities()->canManageMembers();
        $actorId = $this->scope->actorId();
        $environmentCount = $organizationId === null ? 0 : $this->environmentQuery($organizationId)->count();

        return $this->page('console/members', 'Administrators', [
            'members' => collect($roster->items())->map(function (Membership $membership) use ($people, $accessByUser, $actorId, $canManage): array {
                $person = $people[$membership->user_id] ?? null;
                $isSelf = $membership->user_id === $actorId;

                return [
                    'id' => $membership->id,
                    'name' => $person === null ? '—' : ($person->name ?? $person->email ?? '—'),
                    'email' => $person === null ? '—' : ($person->email ?? '—'),
                    'role' => $membership->role->value,
                    'roleLabel' => $membership->role->label(),
                    'isSelf' => $isSelf,
                    'pending' => $membership->status !== MembershipStatus::Active,
                    'status' => $membership->status->value,
                    // The row's own answer, computed once here rather than re-derived in
                    // the browser: the buttons a page draws and the writes the server
                    // accepts must come from one rule.
                    'manageable' => $canManage && ! $isSelf && $membership->role !== MembershipRole::Owner,
                    'scoped' => $membership->role->supportsEnvironmentScoping(),
                    'allEnvironments' => $membership->all_environments === true,
                    'accessCount' => count($accessByUser[$membership->user_id] ?? []),
                ];
            })->values()->all(),
            'pagination' => PaginationProps::from($roster),
            /*
             * The invitations nobody has accepted. Listed because a page that can send one
             * and cannot show it leaves an address holding a live link into the
             * organization for a week with nothing in the product to say so — and because
             * "did that go?" is the question immediately after clicking Send.
             */
            'invitations' => $organizationId === null ? [] : (app(PlatformRoot::class)->run(
                fn (): array => $invitations->pending($organizationId, self::INVITATIONS_SHOWN)
                    ->map(function (Invitation $invitation): array {
                        // `getAttribute`, because `Invitation` documents the columns it
                        // declares and Eloquent's own timestamps are not among them.
                        $invitedAt = $invitation->getAttribute('created_at');

                        return [
                            'id' => $invitation->id,
                            'email' => $invitation->email,
                            'roleLabel' => $invitation->role->label(),
                            // ISO both ways: "expires in 3 days" computed on the server is
                            // wrong the moment the page sits open, and this one sits open.
                            'invitedAt' => $invitedAt instanceof CarbonInterface
                                ? $invitedAt->toIso8601String()
                                : null,
                            'expiresAt' => $invitation->expires_at->toIso8601String(),
                            'expired' => $invitation->expires_at->isPast(),
                        ];
                    })->values()->all(),
            ) ?? []),
            'invitationCount' => $organizationId === null ? 0 : (app(PlatformRoot::class)->run(
                fn (): int => $invitations->countPending($organizationId),
            ) ?? 0),
            'environmentCount' => $environmentCount,
            // Asked of the SCOPE, not of the member row, so the rail, this page's guard
            // and the buttons it renders all answer from one place — and so a person
            // acting on somebody else's organization is refused here too, which a bare
            // role read on the member cannot express.
            'canManage' => $canManage,
            'isOwner' => $this->scope->membershipRole() === MembershipRole::Owner,
            'assignableRoles' => array_map(
                fn (MembershipRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                MembershipRole::assignable(),
            ),
            'editor' => $this->editor($request, $members),
        ]);
    }

    public function invite(
        InviteMemberRequest $request,
        Invitations $invitations,
        Subjects $subjects,
        Memberships $members,
        OrganizationActivity $activity,
        MailLinks $links,
    ): RedirectResponse {
        $organizationId = $this->scope->organizationId();

        abort_if($organizationId === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        /*
         * ONE MESSAGE FOR TWO CASES. The subject lookup is GLOBAL — one email, one root
         * login — so "that email already belongs to a member" let an administrator of one
         * organization probe whether an address belonged to ANOTHER. A member of THIS
         * organization is already visible on the roster below, so nothing is lost to the
         * person entitled to know and nothing is disclosed to the person who is not.
         *
         * A residual signal remains — the invitation fails, so the address exists
         * somewhere — and that is inherent to globally-unique emails, not something a
         * message can hide. Rate limiting and the audit trail are what bound it.
         */
        $existing = app(PlatformRoot::class)->run(fn () => $subjects->findByEmail($request->email()));

        if ($existing !== null
            && app(PlatformRoot::class)->run(fn () => $members->of($organizationId, $existing->id)) !== null) {
            return back()->withInput()->withErrors(['email' => 'That person is already on this list.']);
        }

        $pending = app(PlatformRoot::class)->run(fn () => $invitations->invite(
            $organizationId,
            $request->email(),
            $request->role(),
            $this->scope->actorId(),
        ));

        if ($pending === null) {
            return back();
        }

        // MailLinks, not URL:: — an invitation is mailed, so its origin comes from the
        // deployment rather than from the Host header of whoever asked to send it.
        $url = $links->temporarySignedRoute('organization.invite.accept', now()->addDays(7), ['token' => $pending->token]);

        /*
         * A TRANSPORT FAILURE IS NOT A SUCCESSFUL INVITE. The row is committed by the time
         * the mailer runs, so an SMTP outage used to throw a 500 at the person clicking
         * Send while the invitation sat in the database — invisible, and unrepeatable,
         * because inviting the same address again is refused. It is withdrawn, so the
         * obvious thing to do next (try again) works.
         */
        try {
            Mail::to($request->email())->send(
                new OrganizationInviteMail($this->scope->organizationName() ?? '', $this->scope->actorId(), $url),
            );
        } catch (Throwable $e) {
            app(PlatformRoot::class)->run(fn () => $invitations->revoke($organizationId, $pending->invitation->id));

            report($e);

            return back()->withInput()->withErrors([
                'email' => 'We could not send that invitation — the mail server refused it. Nothing was created; try again, or check the deployment\'s mail configuration.',
            ]);
        }

        $activity->record($organizationId, 'organization.member_invited', $this->scope->actorId(),
            targetType: 'invitation', targetId: $pending->invitation->id,
            context: ['email' => $request->email(), 'role' => $request->role()->value], request: $request);

        return back()->with('status', 'Invitation sent to '.$request->email().'.');
    }

    /**
     * Send the invitation again, to somebody who never got the first one.
     *
     * A NEW TOKEN, not the old one: the mailed link is a signed URL over a token this
     * server only stores hashed, so there is nothing to re-send — and re-issuing is the
     * honest behaviour anyway, since the reason somebody asks is usually that the first
     * link expired. `invite()` supersedes the earlier pending invitation for the same
     * address as part of minting the new one, so the two are never both live.
     */
    public function resendInvite(
        Request $request,
        string $invitation,
        Invitations $invitations,
        OrganizationActivity $activity,
        MailLinks $links,
    ): RedirectResponse {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->requireOrganizationId();

        $found = app(PlatformRoot::class)->run(
            fn () => $invitations->pending($organizationId)->firstWhere('id', $invitation),
        );

        if ($found === null) {
            return back();
        }

        /*
         * ONE MAIL PER MINUTE PER ADDRESS. This is a POST anybody signed in can repeat,
         * `OrganizationInviteMail` is not queued, and the sending domain is shared with
         * every other tenant — so a held-down button is an outbound flood billed to our
         * reputation, not just this organization's.
         */
        $key = 'organization-invite-resend|'.$organizationId.'|'.$found->email;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('error', 'Already sent. Try again in '
                .RateLimiter::availableIn($key).' seconds — and check their spam folder in the meantime.');
        }

        // NO PRE-REVOKE. `invite()` already supersedes every earlier pending invitation
        // for the same address, so revoking first bought nothing — and cost everything
        // when the mail then failed: the original was gone, the replacement was rolled
        // back, and the person was left holding a dead link with no live invitation.
        $pending = app(PlatformRoot::class)->run(fn () => $invitations->invite(
            $organizationId,
            $found->email,
            $found->role,
            $this->scope->actorId(),
        ));

        if ($pending === null) {
            return back();
        }

        $url = $links->temporarySignedRoute('organization.invite.accept', now()->addDays(7), ['token' => $pending->token]);

        try {
            Mail::to($found->email)->send(
                new OrganizationInviteMail($this->scope->organizationName() ?? '', $this->scope->actorId(), $url),
            );
        } catch (Throwable $e) {
            /*
             * THE INVITATION STAYS. Unlike the create path — where rolling back leaves the
             * screen saying nothing happened, which is the truth — here the person already
             * had one, and destroying the replacement on a transport failure would leave
             * them with none at all. A live invitation nobody received is strictly better
             * than no invitation: it is on the list, and the button that failed is the one
             * that retries it.
             */
            report($e);

            return back()->with('error', 'That invitation could not be sent — the mail server refused it. It is still listed below; try again in a moment.');
        }

        // AFTER a successful send, not before it. Charging the window on the way in meant
        // a transport failure told the administrator "try again in a moment" and then
        // answered the retry with "Already sent. Try again in 54 seconds" — about a mail
        // that was never sent.
        RateLimiter::hit($key, 60);

        $activity->record($organizationId, 'organization.member_invited', $this->scope->actorId(),
            targetType: 'invitation', targetId: $pending->invitation->id,
            context: ['email' => $found->email, 'role' => $found->role->value, 'resent' => true], request: $request);

        return back()->with('status', 'Invitation sent again to '.$found->email.'.');
    }

    /**
     * Withdraw an invitation nobody accepted.
     *
     * The other half of being able to SEE them: an address invited by mistake, or somebody
     * who left before accepting, otherwise held a live link into the organization for a
     * week with nothing in the product to stop it.
     */
    public function revokeInvite(
        Request $request,
        string $invitation,
        Invitations $invitations,
        OrganizationActivity $activity,
    ): RedirectResponse {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->requireOrganizationId();

        $found = app(PlatformRoot::class)->run(
            fn () => $invitations->pending($organizationId)->firstWhere('id', $invitation),
        );

        if ($found === null) {
            return back();
        }

        app(PlatformRoot::class)->run(fn () => $invitations->revoke($organizationId, $found->id));

        $activity->record($organizationId, 'organization.invitation_revoked', $this->scope->actorId(),
            targetType: 'invitation', targetId: $found->id,
            context: ['email' => $found->email], request: $request);

        // Back to page one: withdrawing the last row on a later page leaves the paginator
        // asking for a page that no longer exists, and the empty state then claims there
        // is nothing outstanding.
        return to_route('members')->with('status', 'Invitation withdrawn. That link no longer works.');
    }

    public function changeRole(
        Request $request,
        string $member,
        OrganizationActivity $activity,
        Memberships $members,
    ): RedirectResponse {
        $organizationId = $this->scope->organizationId();
        $target = $this->manageableTarget($member);
        $next = MembershipRole::tryFrom((string) $request->string('role'));

        if ($organizationId === null || $target === null || $next === null
            || ! in_array($next, MembershipRole::assignable(), true)) {
            return back();
        }

        // The organization id comes from the SCOPE and the subject id from the fenced
        // lookup, so neither is the string off the wire.
        app(PlatformRoot::class)->run(fn () => $members->changeRole($organizationId, $target->user_id, $next));

        $activity->record($organizationId, 'organization.member_role_changed', $this->scope->actorId(),
            targetType: 'membership', targetId: $target->id,
            context: ['role' => $next->value], request: $request);

        return back()->with('status', 'Role updated.');
    }

    public function removeMember(
        Request $request,
        string $member,
        OrganizationActivity $activity,
        Memberships $members,
    ): RedirectResponse {
        $organizationId = $this->scope->organizationId();
        $target = $this->manageableTarget($member);

        if ($organizationId === null || $target === null) {
            return back();
        }

        app(PlatformRoot::class)->run(fn () => $members->remove($organizationId, $target->user_id));

        $activity->record($organizationId, 'organization.member_removed', $this->scope->actorId(),
            targetType: 'membership', targetId: $target->id, request: $request);

        // Same reason as withdrawing an invitation: removing the last row on a later page
        // leaves the paginator pointed at a page that no longer exists.
        return to_route('members')->with('status', 'Member removed.');
    }

    /**
     * Transfer ownership to another member — current owner only.
     *
     * PROMOTE FIRST, THEN DEMOTE, and the order is load-bearing rather than stylistic.
     * `Memberships` refuses to demote the last owner, so demoting first would be refused
     * outright; promoting first means the organization briefly has two owners and never
     * zero.
     */
    public function makeOwner(string $member, Memberships $members, Subjects $subjects): RedirectResponse
    {
        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        if ($organizationId === null || $this->scope->membershipRole() !== MembershipRole::Owner) {
            return back();
        }

        $target = $this->resolve($member, $organizationId);

        if ($target->user_id === $actorId) {
            return back();
        }

        app(PlatformRoot::class)->run(function () use ($members, $organizationId, $target, $actorId): void {
            $members->changeRole($organizationId, $target->user_id, MembershipRole::Owner);
            $members->changeRole($organizationId, $actorId, MembershipRole::Admin);
        });

        $subject = app(PlatformRoot::class)->run(fn () => $subjects->find($target->user_id));
        $who = $subject === null ? 'that member' : ($subject->name ?? $subject->email ?? 'that member');

        return back()->with('status', 'Ownership transferred to '.$who.'.');
    }

    public function saveAccess(
        SetEnvironmentAccessRequest $request,
        string $member,
        Memberships $members,
    ): RedirectResponse {
        $organizationId = $this->scope->organizationId();
        $target = $this->manageableTarget($member);

        if ($organizationId === null || $target === null) {
            return back();
        }

        app(PlatformRoot::class)->run(fn () => $members->setEnvironmentAccess(
            $organizationId,
            $target->user_id,
            $request->allEnvironments(),
            $request->environmentIds(),
        ));

        return back()->with('status', 'Environment access updated.');
    }

    /**
     * The environment picker, and NOTHING WHEN IT IS CLOSED.
     *
     * Every render of this roster used to load every environment the organization owns,
     * to draw one checkbox each inside a panel that is closed almost all the time. A
     * customer with hundreds of environments paid for that on each page of members, and
     * then, on opening "Edit access", got hundreds of checkboxes with no way to find one.
     *
     * Which member is being edited lives in the URL rather than in component state, so
     * the search that narrows the list is one partial reload rather than a round trip
     * that re-reads the roster.
     *
     * @return array<string, mixed>|null
     */
    private function editor(Request $request, Memberships $members): ?array
    {
        $editing = $request->string('editing')->toString();
        $organizationId = $this->scope->organizationId();

        if ($editing === '' || $organizationId === null) {
            return null;
        }

        $target = $this->manageableTarget($editing);

        // Not manageable, or not a role that CAN be scoped: the editor is not offered
        // rather than offered and then refused on save.
        if ($target === null || ! $target->role->supportsEnvironmentScoping()) {
            return null;
        }

        $search = trim($request->string('envSearch')->toString());

        $rows = $this->environmentQuery($organizationId)
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('created_at')
            // One over the cap tells us there are more without a second query; the extra
            // is not rendered, and the panel says so rather than ending silently.
            ->limit(self::ENVIRONMENTS_PER_EDITOR + 1)
            ->get();

        $truncated = $rows->count() > self::ENVIRONMENTS_PER_EDITOR;

        return [
            'memberId' => $target->id,
            'all' => $target->all_environments === true,
            'selected' => app(PlatformRoot::class)->run(
                fn (): array => $members->accessibleEnvironmentIds($organizationId, $target->user_id),
            ) ?? [],
            'environments' => $rows->take(self::ENVIRONMENTS_PER_EDITOR)
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'sandbox' => $environment->isSandbox(),
                ])->values()->all(),
            'truncated' => $truncated,
            'search' => $search,
        ];
    }

    /**
     * THROUGH THE PROJECTS, because `environments.account_id` is gone.
     *
     * @return Builder<Environment>
     */
    private function environmentQuery(string $organizationId): Builder
    {
        return Environment::query()->whereIn(
            'project_id',
            Project::query()->where('organization_id', $organizationId)->pluck('id'),
        );
    }

    /** The target member IF the acting member may manage it (not self, not the owner). */
    private function manageableTarget(string $memberId): ?Membership
    {
        $organizationId = $this->scope->organizationId();

        // Asked BEFORE the organization fence, and answered silently: a member who may
        // read the roster but not manage it, or one naming themselves, is looking at a
        // person they can genuinely see. A 404 there would deny the existence of a row
        // rendered three lines above it on the same page.
        if ($organizationId === null || $this->scope->capabilities()?->canManageMembers() !== true) {
            return null;
        }

        $target = $this->resolve($memberId, $organizationId);

        if ($target->user_id === $this->scope->actorId()) {
            return null;
        }

        return $target->role === MembershipRole::Owner ? null : $target;
    }

    /**
     * The named membership WITHIN this organization, or 404.
     *
     * THROUGH THE CONTRACT, not a raw query, and that is not a style preference.
     * `memberships` is TENANT-owned as well as environment-owned and the tenant scope is
     * deny-by-default — a bare `Membership::query()` in a console request has no tenant in
     * context and matches NOTHING, so every action here would 404 on a row that is right
     * there on the page. `forOrganization()` runs inside the organization's tenant scope,
     * so the fence is the call itself rather than a predicate a later caller could forget.
     *
     * 404, not 403 — consistent with the rest of the console. A member of somebody else's
     * organization is not a permission this person lacks; it is a row they have no
     * business learning exists.
     */
    private function resolve(string $memberId, string $organizationId): Membership
    {
        $target = app(PlatformRoot::class)->run(
            fn (): ?Membership => app(Memberships::class)->forOrganization($organizationId)
                ->firstWhere('id', $memberId),
        );

        abort_if($target === null, 404);

        return $target;
    }
}
