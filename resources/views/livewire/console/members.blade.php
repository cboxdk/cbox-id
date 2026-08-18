<?php

declare(strict_types=1);

use Livewire\Livewire;
use Livewire\WithPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use App\Mail\OrganizationInviteMail;
use App\Platform\OrganizationActivity;
use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use App\Platform\MailLinks;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Members — the organization's team, roles, per-environment access and
 * invitations. Managing members requires a management role; everyone else sees a read-only
 * roster.
 *
 * IDENTITY AND AUTHORITY ARE TWO ROWS NOW. A member used to be one `account_members` row
 * carrying both the person (email, name) and what they may do (role, environment grants).
 * It is a {@see Membership} for the authority and a subject for the person, so every place
 * that rendered `$member->email` resolves the subject instead. That is the whole reason the
 * roster hydrates subjects alongside the memberships rather than reading names off them.
 */
new #[Layout('components.layouts.app', ['title' => 'Administrators'])] class extends Component
{
    use WithPagination;

    /**
     * Rows per page.
     *
     * 25, like every other list in this console, and it is the number that turns this page
     * from one whose cost grows with the organization into one that does not.
     */
    private const PER_PAGE = 25;

    public string $inviteEmail = '';

    public string $inviteName = '';

    public string $inviteRole = 'developer';

    /** The member whose environment access is being edited, if any. */
    public ?string $editingAccessFor = null;

    public bool $accessAll = true;

    /** @var list<string> */
    public array $accessEnvIds = [];

    /**
     * THE UPDATE PATH TOO, not only the first request.
     *
     * The check below lived in mount() alone, and Livewire runs mount() once: a page
     * already open re-hydrates from its snapshot and calls render()/with() straight
     * through, so a person downgraded out of this capability kept a working page. Their
     * browser went on posting to /livewire/update and going on receiving the roster and its pending invitations — PII, every time for as
     * long as the tab stayed open — authorization that expired when the user navigated
     * rather than when their access did.
     *
     * Only on the update path, and 403 rather than a redirect. A first request that fails
     * this is somebody arriving where they may not go, and mount() sends them somewhere
     * they can be — the console's own answer, and the one the navigation-honesty test
     * holds us to. An update that fails it is a page that stopped being theirs while they
     * were holding it, and there is nothing to redirect: the response is a JSON patch.
     */
    public function boot(ConsoleScope $scope): void
    {
        if (! Livewire::isLivewireRequest()) {
            return;
        }

        abort_unless($scope->capabilities()?->canReadMembers() === true, 403);
    }

    public function mount(ConsoleScope $scope): mixed
    {
        // The roster is PII — a Developer/Billing-only role may not read it.
        if ($scope->capabilities()?->canReadMembers() !== true) {
            return redirect()->route('projects');
        }

        return null;
    }

    public function invite(ConsoleScope $scope, Invitations $invitations, Subjects $subjects, OrganizationActivity $activity, MailLinks $links): void
    {
        $organizationId = $scope->organizationId();

        if ($organizationId === null || $scope->capabilities()?->canManageMembers() !== true) {
            return;
        }

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:190'],
            'inviteName' => ['nullable', 'string', 'max:120'],
            'inviteRole' => ['required', Rule::in(array_map(fn (MembershipRole $r) => $r->value, MembershipRole::assignable()))],
        ]);

        // The subject lookup is GLOBAL — one email, one root login — so the old message,
        // "that email already belongs to a member", let an admin of one organization probe
        // whether any address belonged to ANOTHER. One message for both cases closes that:
        // a member of THIS organization is already visible on the roster below, so nothing
        // is lost to the person entitled to know and nothing is disclosed to the person who
        // is not.
        //
        // A residual signal remains — the invitation fails, so the address exists somewhere
        // — and that is inherent to globally-unique emails, not something a message can
        // hide. Rate limiting and the audit trail are what bound it.
        $existing = app(PlatformRoot::class)->run(fn () => $subjects->findByEmail($this->inviteEmail));

        if ($existing !== null && app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($organizationId, $existing->id)) !== null) {
            $this->addError('inviteEmail', 'That email cannot be invited to this organization.');

            return;
        }

        $pending = app(PlatformRoot::class)->run(fn () => $invitations->invite(
            $organizationId,
            $this->inviteEmail,
            MembershipRole::from($this->inviteRole),
            $scope->actorId(),
        ));

        if ($pending === null) {
            return;
        }

        // MailLinks, not URL:: — an invitation is mailed, so its origin must come from the
        // deployment rather than from the Host header of whoever asked to send it.
        $url = $links->temporarySignedRoute('organization.invite.accept', now()->addDays(7), ['token' => $pending->token]);

        // A TRANSPORT FAILURE IS NOT A SUCCESSFUL INVITE. The row is already committed by
        // the time the mailer runs, so an SMTP outage used to throw a 500 at the person
        // clicking Send while the invitation sat in the database — invisible, because this
        // page listed no pending invitations, and unrepeatable, because inviting the same
        // address again is refused. They are told what happened and the invitation is
        // withdrawn, so the obvious thing to do next (try again) works.
        try {
            Mail::to($this->inviteEmail)->send(new OrganizationInviteMail($scope->organizationName() ?? '', $scope->actorId(), $url));
        } catch (Throwable $e) {
            app(PlatformRoot::class)->run(fn () => $invitations->revoke($organizationId, $pending->invitation->id));

            report($e);

            $this->addError('inviteEmail', 'We could not send that invitation — the mail server refused it. Nothing was created; try again, or check the deployment\'s mail configuration.');

            return;
        }

        $activity->record($organizationId, 'organization.member_invited', $scope->actorId(),
            targetType: 'invitation', targetId: $pending->invitation->id,
            context: ['email' => $this->inviteEmail, 'role' => $this->inviteRole], request: request());

        $sentTo = $this->inviteEmail;
        $this->reset('inviteEmail', 'inviteName');
        $this->dispatch('toast', message: 'Invitation sent to '.$sentTo.'.');
    }

    /**
     * Send the invitation again, to somebody who never got the first one.
     *
     * A NEW TOKEN, not the old one: the mailed link is a signed URL over a token this
     * server only stores hashed, so there is nothing to re-send — and re-issuing is the
     * honest behaviour anyway, since the reason somebody asks is usually that the first
     * link expired. `InvitationService::invite()` supersedes the earlier pending
     * invitation for the same address as part of minting the new one, so the two are never
     * both live and nothing here has to revoke first.
     */
    public function resendInvite(string $invitationId, ConsoleScope $scope, Invitations $invitations, OrganizationActivity $activity, MailLinks $links): void
    {
        $scope->assertMayAdminister();

        $organizationId = $scope->requireOrganizationId();

        $invitation = app(PlatformRoot::class)->run(
            fn () => $invitations->pending($organizationId)->firstWhere('id', $invitationId),
        );

        if ($invitation === null) {
            return;
        }

        // ONE MAIL PER MINUTE PER ADDRESS. A component action is a POST anybody signed in
        // can repeat, `OrganizationInviteMail` is not queued, and the sending domain is
        // shared with every other tenant — so a held-down button is an outbound flood
        // billed to our reputation, not just this organization's. The self-service resend
        // on the projects page is throttled for exactly this reason; this one is the
        // higher-privilege sibling and had nothing.
        $key = 'organization-invite-resend|'.$organizationId.'|'.$invitation->email;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->dispatch('toast', message: 'Already sent. Try again in '.RateLimiter::availableIn($key).' seconds — and check their spam folder in the meantime.', severity: 'error');

            return;
        }


        // NO PRE-REVOKE. `InvitationService::invite()` already supersedes every earlier
        // pending invitation for the same address, so revoking first bought nothing — and
        // cost everything when the mail then failed: the original was gone, the
        // replacement was rolled back, and the person was left holding a dead link with
        // no live invitation anywhere.
        $pending = app(PlatformRoot::class)->run(fn () => $invitations->invite(
            $organizationId,
            $invitation->email,
            $invitation->role,
            $scope->actorId(),
        ));

        if ($pending === null) {
            return;
        }

        $url = $links->temporarySignedRoute('organization.invite.accept', now()->addDays(7), ['token' => $pending->token]);

        try {
            Mail::to($invitation->email)->send(new OrganizationInviteMail($scope->organizationName() ?? '', $scope->actorId(), $url));
        } catch (Throwable $e) {
            // THE INVITATION STAYS. Unlike the create path — where rolling back leaves
            // the screen saying nothing happened, which is the truth — here the person
            // already had one, and destroying the replacement on a transport failure
            // would leave them with none at all. A live invitation nobody received is
            // strictly better than no invitation: it is on the list below, and the button
            // that failed is the one that retries it.
            report($e);

            $this->dispatch('toast', message: 'That invitation could not be sent — the mail server refused it. It is still listed below; try again in a moment.', severity: 'error');

            return;
        }

        // AFTER a successful send, not before it. Charging the window on the way in meant
        // a transport failure told the admin "try again in a moment" and then answered the
        // retry with "Already sent. Try again in 54 seconds" — about a mail that was never
        // sent.
        RateLimiter::hit($key, 60);

        $activity->record($organizationId, 'organization.member_invited', $scope->actorId(),
            targetType: 'invitation', targetId: $pending->invitation->id,
            context: ['email' => $invitation->email, 'role' => $invitation->role->value, 'resent' => true], request: request());

        $this->dispatch('toast', message: 'Invitation sent again to '.$invitation->email.'.');
    }

    /**
     * Withdraw an invitation nobody accepted.
     *
     * The other half of being able to SEE them: an address invited by mistake, or somebody
     * who left before accepting, otherwise held a live link into the organization for a
     * week with nothing in the product to stop it.
     */
    public function revokeInvite(string $invitationId, ConsoleScope $scope, Invitations $invitations, OrganizationActivity $activity): void
    {
        $scope->assertMayAdminister();

        $organizationId = $scope->requireOrganizationId();

        $invitation = app(PlatformRoot::class)->run(
            fn () => $invitations->pending($organizationId)->firstWhere('id', $invitationId),
        );

        if ($invitation === null) {
            return;
        }

        app(PlatformRoot::class)->run(fn () => $invitations->revoke($organizationId, $invitation->id));

        $activity->record($organizationId, 'organization.invitation_revoked', $scope->actorId(),
            targetType: 'invitation', targetId: $invitation->id,
            context: ['email' => $invitation->email], request: request());

        // Back to page one: withdrawing the last row on a later page leaves the paginator
        // asking for a page that no longer exists, and the empty state then claims there
        // is nothing outstanding.
        $this->resetPage();

        $this->dispatch('toast', message: 'Invitation withdrawn. That link no longer works.');
    }

    public function changeRole(string $memberId, string $role, ConsoleScope $scope, OrganizationActivity $activity, Memberships $members): void
    {
        $organizationId = $scope->organizationId();
        $target = $this->manageableTarget($memberId, $scope);
        $next = MembershipRole::tryFrom($role);

        if ($organizationId === null || $target === null || $next === null || ! in_array($next, MembershipRole::assignable(), true)) {
            return;
        }

        // The organization id comes from the SCOPE and the subject id from the fenced
        // lookup, so neither is the string off the wire.
        app(PlatformRoot::class)->run(fn () => $members->changeRole($organizationId, $target->user_id, $next));

        $activity->record($organizationId, 'organization.member_role_changed', $scope->actorId(),
            targetType: 'membership', targetId: $target->id,
            context: ['role' => $next->value], request: request());

        $this->dispatch('toast', message: 'Role updated.');
    }

    public function removeMember(string $memberId, ConsoleScope $scope, OrganizationActivity $activity, Memberships $members): void
    {
        $organizationId = $scope->organizationId();
        $target = $this->manageableTarget($memberId, $scope);

        if ($organizationId === null || $target === null) {
            return;
        }

        app(PlatformRoot::class)->run(fn () => $members->remove($organizationId, $target->user_id));

        $activity->record($organizationId, 'organization.member_removed', $scope->actorId(),
            targetType: 'membership', targetId: $target->id, request: request());

        // Same reason as withdrawing an invitation: removing the last row on a later page
        // leaves the paginator pointed at a page that no longer exists.
        $this->resetPage();

        $this->dispatch('toast', message: 'Member removed.');
    }

    /**
     * Transfer ownership to another member — current owner only.
     *
     * PROMOTE FIRST, THEN DEMOTE, and the order is load-bearing rather than stylistic.
     * `Memberships` refuses to demote the last owner, so demoting first would be refused
     * outright; promoting first means the organization briefly has two owners and never
     * zero. The account plane had a single `transferOwnership()` verb that did both inside
     * one transaction; there is no such verb here, so the invariant has to be respected by
     * the order rather than by the service.
     */
    public function makeOwner(string $memberId, ConsoleScope $scope, Memberships $members, Subjects $subjects): void
    {
        $organizationId = $scope->organizationId();
        $actorId = $scope->actorId();

        if ($organizationId === null || $scope->membershipRole() !== MembershipRole::Owner) {
            return;
        }

        $target = $this->resolve($memberId, $organizationId);

        if ($target->user_id === $actorId) {
            return;
        }

        app(PlatformRoot::class)->run(function () use ($members, $organizationId, $target, $actorId): void {
            $members->changeRole($organizationId, $target->user_id, MembershipRole::Owner);
            $members->changeRole($organizationId, $actorId, MembershipRole::Admin);
        });

        $subject = app(PlatformRoot::class)->run(fn () => $subjects->find($target->user_id));
        $who = $subject === null ? 'that member' : ($subject->name ?? $subject->email ?? 'that member');

        $this->dispatch('toast', message: 'Ownership transferred to '.$who.'.');
    }

    public function manageAccess(string $memberId, ConsoleScope $scope, Memberships $members): void
    {
        $organizationId = $scope->organizationId();
        $target = $this->manageableTarget($memberId, $scope);

        if ($organizationId === null || $target === null || ! $target->role->supportsEnvironmentScoping()) {
            return;
        }

        $this->editingAccessFor = $target->id;
        $this->accessAll = $target->all_environments === true;
        $this->accessEnvIds = app(PlatformRoot::class)->run(
            fn (): array => $members->accessibleEnvironmentIds($organizationId, $target->user_id),
        ) ?? [];
    }

    public function saveAccess(ConsoleScope $scope, Memberships $members): void
    {
        $organizationId = $scope->organizationId();

        if ($this->editingAccessFor === null || $organizationId === null) {
            return;
        }

        $target = $this->manageableTarget($this->editingAccessFor, $scope);

        if ($target === null) {
            return;
        }

        app(PlatformRoot::class)->run(
            fn () => $members->setEnvironmentAccess($organizationId, $target->user_id, $this->accessAll, $this->accessEnvIds),
        );
        $this->editingAccessFor = null;
        $this->dispatch('toast', message: 'Environment access updated.');
    }

    public function cancelAccess(): void
    {
        $this->editingAccessFor = null;
    }

    /** The target member IF the acting member may manage it (not self, not the owner). */
    private function manageableTarget(string $memberId, ConsoleScope $scope): ?Membership
    {
        $organizationId = $scope->organizationId();

        // Asked BEFORE the organization fence, and answered silently: a member who may read
        // the roster but not manage it, or one naming themselves, is looking at a person
        // they can genuinely see. A 404 there would deny the existence of a row rendered
        // three lines above it on the same page.
        if ($organizationId === null || $scope->capabilities()?->canManageMembers() !== true) {
            return null;
        }

        $target = $this->resolve($memberId, $organizationId);

        if ($target->user_id === $scope->actorId()) {
            return null;
        }

        return $target->role === MembershipRole::Owner ? null : $target;
    }

    /**
     * The named membership WITHIN this organization, or 404.
     *
     * THE ORGANIZATION ID IS IN THE QUERY. A lookup on the primary key alone spans every
     * organization on the install, and what would stand between an admin of one and a
     * member of another is an `if` afterwards. That comparison does hold, but it is the
     * same shape that shipped a cross-organization IDOR on /governance/{campaign}: the
     * predicate has to be in the query, or the next caller added to this file is the one
     * that forgets to re-check.
     *
     * The service verbs now take `(organizationId, userId)` rather than a bare membership
     * id, so the fence and the write agree by signature. This still resolves first, because
     * the page hands us a MEMBERSHIP id off the wire and the organization it belongs to is
     * exactly the thing that must not be taken on trust.
     *
     * 404, not 403 — consistent with the rest of the console. A member of somebody else's
     * organization is not a permission this person lacks; it is a row they have no business
     * learning exists.
     */
    private function resolve(string $memberId, string $organizationId): Membership
    {
        // THROUGH THE CONTRACT, not a raw query, and that is not a style preference.
        // `memberships` is TENANT-owned as well as environment-owned, and the tenant scope
        // is deny-by-default — a bare `Membership::query()` in a console request has no
        // tenant in context and matches NOTHING, so every action here would 404 on a row
        // that is right there on the page. `account_members` was not tenant-owned, which is
        // why the query this replaces could be written by hand and why substituting the
        // model one-for-one looked safe.
        //
        // `forOrganization()` runs inside the organization's tenant scope, so the fence is
        // the call itself rather than a predicate a later caller could forget.
        $target = app(PlatformRoot::class)->run(
            fn (): ?Membership => app(Memberships::class)->forOrganization($organizationId)
                ->firstWhere('id', $memberId),
        );

        abort_if($target === null, 404);

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Memberships $members, Subjects $subjects, ConsoleScope $scope): array
    {
        $organizationId = $scope->organizationId();

        // PAGINATED, and it always should have been. `forOrganization()` hydrated the whole
        // roster and the loop below asked two more questions per row — measured at 10
        // queries and ~13 KB per member, so a 101-member organization served a 1.3 MB
        // document off 1037 queries, and a 500-member one is an availability failure rather
        // than a slow page. The sibling roster at `livewire/members.blade.php` has been the
        // correct shape all along; this is that shape.
        //
        // IN THE PLATFORM ROOT, like every other membership read on this page. The console
        // is served on the console host, and whether that host resolves to the root is a
        // deployment detail — the management plane's rows live in the root either way, so
        // the scope is stated rather than assumed.
        /** @var LengthAwarePaginator<int, Membership> $roster */
        $roster = $organizationId === null
            ? new Paginator([], 0, self::PER_PAGE)
            : (app(PlatformRoot::class)->run(
                fn (): LengthAwarePaginator => $members->paginateForOrganization($organizationId, self::PER_PAGE),
            ) ?? new Paginator([], 0, self::PER_PAGE));

        /** @var list<string> $userIds */
        $userIds = collect($roster->items())->pluck('user_id')->all();

        // The people behind the memberships, keyed by subject id, in ONE query. A
        // membership carries authority and not identity, so every name and address on this
        // page is a second lookup — and doing it inside the loop is how a 25-row roster
        // becomes 25 queries. That sentence was already written here, directly above a
        // loop that did exactly what it warns against.
        $people = $userIds === [] ? [] : (app(PlatformRoot::class)->run(
            fn (): array => $subjects->findMany($userIds),
        ) ?? []);

        // And the environment access per row, also in one pass. What the organization owns
        // is a property of the ORGANIZATION rather than of each member, and asking it once
        // per row was most of the cost.
        $accessByUser = ($organizationId === null || $userIds === []) ? [] : (app(PlatformRoot::class)->run(
            fn (): array => $members->accessibleEnvironmentIdsFor($organizationId, $userIds),
        ) ?? []);

        $accessCounts = [];

        foreach ($roster->items() as $membership) {
            $accessCounts[$membership->id] = $accessByUser[$membership->user_id] ?? [];
        }

        // THROUGH THE PROJECTS, because `environments.account_id` is gone.
        $environments = $organizationId === null
            ? collect()
            : Environment::query()
                ->whereIn('project_id', Project::query()->where('organization_id', $organizationId)->pluck('id'))
                ->orderBy('created_at')
                ->get();

        return [
            // The invitations nobody has accepted. Listed because a page that can send one
            // and cannot show it leaves an address holding a live link into the
            // organization for a week with nothing in the product to say so — and because
            // "did that go?" is the question immediately after clicking Send.
            'invitations' => $organizationId === null ? collect() : (app(PlatformRoot::class)->run(
                fn () => app(Invitations::class)->pending($organizationId),
            ) ?? collect()),
            'members' => $roster,
            'people' => $people,
            'actorId' => $scope->actorId(),
            'accessCounts' => $accessCounts,
            'environments' => $environments,
            // Asked of the SCOPE, not of the member row, so the rail, this page's own
            // guard and the buttons it renders all answer from one place — and so a
            // person acting on somebody else's organization is refused here too, which
            // a bare role read on the member cannot express.
            'canManage' => $scope->capabilities()?->canManageMembers() === true,
            'isOwner' => $scope->membershipRole() === MembershipRole::Owner,
            'assignableRoles' => MembershipRole::assignable(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Administrators" subtitle="People who can administer this organization, their roles, and which environments they reach." />

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @foreach ($members as $m)
            @php
                $person = $people[$m->user_id] ?? null;
                $displayName = $person?->name ?? $person?->email ?? '—';
                $displayEmail = $person?->email ?? '—';
                $isSelf = $m->user_id === $actorId;
                $manageable = $canManage && ! $isSelf && $m->role !== \Cbox\Id\Organization\Enums\MembershipRole::Owner;
                $scoped = $m->role->supportsEnvironmentScoping();
                // Built here rather than inline: a Blade `:attr` value cannot itself
                // contain the double quote the action string needs around the id.
                $makeOwnerAction = "makeOwner('{$m->id}')";
                $removeMemberAction = "removeMember('{$m->id}')";
            @endphp
            <div wire:key="member-{{ $m->id }}" class="p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center w-9 h-9 rounded-full text-sm font-semibold shrink-0" style="background:var(--surface-2);color:var(--muted)" aria-hidden="true">{{ strtoupper(substr($displayName, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium truncate">{{ $displayName }}</span>
                            @if ($isSelf)<span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent-strong)">You</span>@endif
                            @if ($m->status !== \Cbox\Id\Organization\Enums\MembershipStatus::Active)<span class="badge badge-warn">{{ $m->status->value }}</span>@endif
                        </div>
                        <p class="text-sm truncate" style="color:var(--muted)">{{ $displayEmail }}</p>
                    </div>

                    @if ($manageable)
                        <select class="input shrink-0" style="width:auto;padding-top:6px;padding-bottom:6px"
                                wire:change="changeRole('{{ $m->id }}', $event.target.value)" aria-label="Role for {{ $displayEmail }}">
                            @foreach ($assignableRoles as $role)
                                <option value="{{ $role->value }}" @selected($m->role === $role)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        <div x-data="{ open: false }" class="relative shrink-0">
                            <button type="button" @click="open = !open" @click.outside="open = false" class="btn btn-ghost btn-sm" aria-label="More actions">⋯</button>
                            <div x-show="open" x-cloak class="cbx-panel" style="position:absolute;right:0;top:calc(100% + 4px);min-width:190px;z-index:20;box-shadow:var(--shadow-popover);padding:6px">
                                @if ($scoped)
                                    <button type="button" class="cbx-row w-full" style="padding:8px 10px;border-radius:6px;font-size:13px" wire:click="manageAccess('{{ $m->id }}')" @click="open = false">Manage environment access</button>
                                @endif
                                @if ($isOwner)
                                    <x-confirm-delete
                                        :name="$displayEmail"
                                        :action="$makeOwnerAction"
                                        label="Transfer ownership"
                                        verb="Hand this organization to"
                                        trigger-class="cbx-row w-full"
                                        trigger-style="padding:8px 10px;border-radius:6px;font-size:13px"
                                        consequence="They become the organization owner and you are demoted to admin. Only the new owner can hand it back." />
                                @endif
                                <x-confirm-delete
                                    :name="$displayEmail"
                                    :action="$removeMemberAction"
                                    label="Remove"
                                    verb="Remove"
                                    trigger-class="cbx-row w-full"
                                    trigger-style="padding:8px 10px;border-radius:6px;font-size:13px;color:var(--destructive)"
                                    consequence="They lose access to this organization and every environment under it immediately." />
                            </div>
                        </div>
                    @else
                        <span class="badge shrink-0">{{ $m->role->label() }}</span>
                    @endif
                </div>

                {{-- Environment-access summary + inline editor for scoped roles. --}}
                @if ($scoped)
                    <div class="mt-2 ml-12 text-xs" style="color:var(--faint)">
                        @if ($m?->all_environments === true)
                            Access to all environments
                        @else
                            Access to {{ count($accessCounts[$m->id] ?? []) }} of {{ $environments->count() }} environments
                        @endif
                    </div>
                    @if ($editingAccessFor === $m->id)
                        <div class="mt-3 ml-12 rounded-lg border p-3" style="border-color:var(--border)">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model.live="accessAll"> All environments (including ones added later)
                            </label>
                            <div class="mt-2 space-y-1.5" @if ($accessAll) style="opacity:0.4;pointer-events:none" @endif>
                                @foreach ($environments as $env)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" value="{{ $env->id }}" wire:model="accessEnvIds" @disabled($accessAll)>
                                        {{ $env->name }} @if ($env->isSandbox())<span style="color:var(--warning-strong)">· sandbox</span>@endif
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="saveAccess">Save access</button>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelAccess">Cancel</button>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if ($members->hasPages())
        <div class="mt-4">{{ $members->links() }}</div>
    @endif

    @if ($invitations->isNotEmpty())
        <section class="cbx-panel mt-6">
            <div class="cbx-panel-header">
                <div>
                    <h2 class="cbx-panel-title">Invited, not joined yet</h2>
                    <p class="cbx-panel-desc">These links work until they expire or you withdraw them.</p>
                </div>
            </div>
            <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:8px">
                @foreach ($invitations as $invitation)
                    {{-- flex-wrap: at 375px the address, the role badge and two buttons do
                         not fit on one line, and without it the address truncated to
                         "teammate@yo…" — which is the one thing on the row a person needs
                         to read. --}}
                    <div wire:key="invite-{{ $invitation->id }}" class="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm truncate">{{ $invitation->email }} <span class="badge ml-1">{{ $invitation->role->label() }}</span></p>
                            <p class="text-xs truncate" style="color:var(--faint)">
                                invited {{ $invitation->created_at?->diffForHumans() ?? 'recently' }} ·
                                @if ($invitation->expires_at->isPast())
                                    <span style="color:var(--destructive)">expired {{ $invitation->expires_at->diffForHumans() }}</span>
                                @else
                                    expires {{ $invitation->expires_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        @if ($canManage)
                            {{-- The accessible name carries the address. Six pending
                                 invitations otherwise give a screen-reader user six
                                 buttons called "Send again" and six called "Withdraw",
                                 with nothing to tell them apart. --}}
                            <button type="button" class="btn btn-ghost btn-sm shrink-0"
                                    aria-label="Send the invitation to {{ $invitation->email }} again"
                                    wire:click="resendInvite('{{ $invitation->id }}')"
                                    wire:loading.attr="disabled" wire:target="resendInvite">
                                <span wire:loading.remove wire:target="resendInvite">Send again</span>
                                <span wire:loading wire:target="resendInvite">Sending&hellip;</span>
                            </button>
                            {{-- `wire:confirm`, NOT type-to-confirm. The confirm-delete
                                 component is for what cannot be undone from the console,
                                 and its own docblock warns that making a reversible action
                                 cost a typed name trains people to type names without
                                 reading them. Withdrawing is reversible — the consequence
                                 text says so itself — and the string you would have to type
                                 is the email address, when the commonest reason to withdraw
                                 is that the address was wrong. --}}
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)"
                                    aria-label="Withdraw the invitation to {{ $invitation->email }}"
                                    wire:click="revokeInvite('{{ $invitation->id }}')"
                                    wire:confirm="Withdraw the invitation to {{ $invitation->email }}? The link they were sent stops working immediately. You can invite them again afterwards."
                                    wire:loading.attr="disabled" wire:target="revokeInvite">
                                Withdraw
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($canManage)
        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm font-medium">Invite a teammate</p>
            <p class="mt-1 text-sm" style="color:var(--muted)">They'll get an email to set a password and join this organization.</p>
            <form wire:submit="invite" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto_auto] gap-2 items-start">
                <div>
                    <input wire:model="inviteEmail" type="email" class="input" placeholder="teammate@yourco.example" autocomplete="off" aria-label="Teammate email">
                    @error('inviteEmail') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <input wire:model="inviteName" type="text" class="input" placeholder="Name (optional)" autocomplete="off" aria-label="Teammate name">
                <select wire:model="inviteRole" class="input" style="width:auto" aria-label="Role">
                    @foreach ($assignableRoles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="invite">
                    <span wire:loading.remove wire:target="invite">Send invite</span>
                    <span wire:loading wire:target="invite">Sending…</span>
                </button>
            </form>
        </div>
    @endif
</div>
