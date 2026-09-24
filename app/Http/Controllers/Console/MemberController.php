<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Http\Requests\Console\InviteMemberRequest;
use App\Http\Requests\Console\SetEnvironmentAccessRequest;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\Contracts\TeamInvitations;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefused;
use App\Platform\OrganizationActivity;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Inertia\Response;

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
        TeamInvitations $team,
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

        return $this->page('console/members', 'Team', [
            'help' => HelpProps::for(HelpTopic::Team),
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
            'invitations' => $organizationId === null ? [] : $this->pendingInvitations($organizationId, $canManage, $team),
            'invitationCount' => $organizationId === null ? 0 : $team->countPending($organizationId),
            'environmentCount' => $environmentCount,
            // Asked of the SCOPE, not of the member row, so the rail, this page's guard
            // and the buttons it renders all answer from one place — and so a person
            // acting on somebody else's organization is refused here too, which a bare
            // role read on the member cannot express.
            'canManage' => $canManage,
            'isOwner' => $this->scope->membershipRole() === MembershipRole::Owner,
            // The same picker every invite surface draws, with this page's own list: an
            // account's administrators hold different roles from an organization's people.
            'assignableRoles' => RoleOptionProps::account(),
            'editor' => $this->editor($request, $members),
        ]);
    }

    public function invite(InviteMemberRequest $request, TeamInvitations $team, Subjects $subjects): RedirectResponse
    {
        $organizationId = $this->scope->organizationId();

        abort_if($organizationId === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        // The same service the workspace API's `POST /v1/organization/members` uses, so the
        // two doors refuse, mail and record exactly alike ({@see TeamInvitations}).
        try {
            $team->send($organizationId, $request->email(), $request->role(), $this->inviter($subjects), $this->actor());
        } catch (InvitationRefused $refused) {
            return back()->withInput()->withErrors(['email' => $refused->getMessage()]);
        }

        return back()->with('status', 'Invitation sent to '.$request->email().'.');
    }

    /**
     * Send the invitation again, to somebody who never got the first one — a fresh link;
     * the earlier one stops working. At most once a minute per address.
     */
    public function resendInvite(string $invitation, TeamInvitations $team, Subjects $subjects): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->requireOrganizationId();

        try {
            $sent = $team->resend($organizationId, $invitation, $this->inviter($subjects), $this->actor());
        } catch (InvitationRefused $refused) {
            // An id that is not a pending invitation on this team is answered with nothing,
            // as before: it is a row this person was never shown.
            return $refused->reason === InvitationRefusalReason::NotPending
                ? back()
                : back()->with('error', $refused->getMessage());
        }

        return back()->with('status', 'Invitation sent again to '.$sent->email.'.');
    }

    /**
     * Withdraw an invitation nobody accepted.
     *
     * The other half of being able to SEE them: an address invited by mistake, or somebody
     * who left before accepting, otherwise held a live link into the organization for a
     * week with nothing in the product to stop it.
     */
    public function revokeInvite(string $invitation, TeamInvitations $team): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->requireOrganizationId();

        try {
            $team->revoke($organizationId, $invitation, $this->actor());
        } catch (InvitationRefused) {
            return back();
        }

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
    public function makeOwner(string $member, MembershipLifecycle $lifecycle, Subjects $subjects): RedirectResponse
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

        // THE SAME VERB the organization's own People page uses — promote, then demote, in
        // one transaction — run in the platform root, where an account's memberships live.
        try {
            app(PlatformRoot::class)->run(
                fn () => $lifecycle->transferOwnership($organizationId, $target->user_id, $actorId, $actorId),
            );
        } catch (MembershipRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

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

    /**
     * The team's pending invitations, in the shape every invite surface lists.
     *
     * @return list<PendingInvitationProps>
     */
    private function pendingInvitations(string $organizationId, bool $canManage, TeamInvitations $team): array
    {
        return array_map(
            static fn (PendingInvitationSummary $invitation): PendingInvitationProps => PendingInvitationProps::from(
                $invitation,
                $canManage ? route('members.invitations.resend', $invitation->id) : null,
                $canManage ? route('members.invitations.revoke', $invitation->id) : null,
            ),
            $team->pending($organizationId, self::INVITATIONS_SHOWN),
        );
    }

    /**
     * Who is sending: the acting member, by NAME.
     *
     * The mail was once handed `actorId()` — the inviter's subject ULID — so every team
     * invitation arrived reading "01J9… invited you to help run Acme".
     */
    private function inviter(Subjects $subjects): Inviter
    {
        $actorId = $this->scope->actorId();
        $subject = app(PlatformRoot::class)->run(fn () => $subjects->find($actorId));

        return new Inviter($actorId, $subject === null ? 'A teammate' : ($subject->name ?? $subject->email ?? 'A teammate'));
    }

    private function actor(): AuditActor
    {
        return AuditActor::organizationMember($this->scope->actorId());
    }
}
