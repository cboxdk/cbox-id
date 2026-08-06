<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use App\Platform\GrantAccessRole;
use App\Platform\MailLinks;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use App\Platform\SodGuard;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app', ['title' => 'Members'])] class extends Component
{
    use WithPagination;

    #[Validate('required|email|max:190')]
    public string $inviteEmail = '';

    /**
     * Rules live in {@see self::rules()} rather than a #[Validate] attribute: the rule
     * is a Rule object derived from {@see OrgRoles::assignable()}, which an attribute
     * (a constant expression) cannot express — and a hand-copied `in:` list is exactly
     * the drift this enum exists to prevent.
     */
    public string $inviteRole = 'member';

    /** @var array<int, string> Access-role ids to grant the invitee on acceptance. */
    public array $inviteAccessRoles = [];

    public bool $inviting = false;

    /**
     * Merged with the #[Validate] attribute rules by Livewire, so `$this->validate()`
     * covers both.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['inviteRole' => ['required', OrgRoles::rule()]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['inviteRole' => OrgRoles::message()];
    }

    /** The member whose access-roles panel is expanded, if any. */
    public ?string $managingUserId = null;

    public function toggleManage(string $userId): void
    {
        $this->authorizeAdmin();
        $this->managingUserId = $this->managingUserId === $userId ? null : $userId;
    }

    /** Assign or remove an org/app access-role for a member (manual grant). */
    public function toggleRole(string $userId, string $roleId, Roles $roles, Memberships $memberships, OrgAccessRoles $catalog): void
    {
        $this->authorizeAdmin();

        // Server-side authorization, not just a hidden button: the target must be a real
        // member of THIS org, and the role must be one genuinely assignable here (which
        // excludes another org's private-app roles). Without this, a forged Livewire
        // toggleRole could bind an arbitrary role id — the framework's RoleService is the
        // backstop, but the assignable/membership contract is the gate. Mirrors the
        // env-admin console's toggleAccessRole.
        if ($memberships->of($this->orgId(), $userId) === null || ! $catalog->isAssignable($this->orgId(), $roleId)) {
            return;
        }

        $held = RoleAssignment::query()
            ->where('organization_id', $this->orgId())
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();

        if ($held) {
            $roles->unassign($this->orgId(), $userId, $roleId);

            return;
        }

        // Segregation of duties is a PRE-GRANT gate the host has to call — the contract
        // says so, and evaluate()/wouldViolate() are the whole published API. The console
        // shipped the SoD screens and never called it, so an admin could create on the
        // Members page exactly the toxic combination the Governance page reports.
        $refusal = app(GrantAccessRole::class)->grant($this->orgId(), $userId, $roleId, GrantSource::Manual);

        if ($refusal !== null) {
            $this->dispatch('toast', message: $refusal->message(), severity: 'error');
        }
    }

    public function invite(Invitations $invitations, MailLinks $links): void
    {
        $this->authorizeAdmin();
        $this->validate();

        // Safe to parse rather than tryFrom: the rule above is derived from the same
        // assignable set, so a value that reached here is a case of the enum.
        $role = MembershipRole::from($this->inviteRole);

        // Only an owner may invite someone straight to owner.
        abort_if($role === MembershipRole::Owner && ! app(CurrentUser::class)->isOwner(), 403);

        $me = app(CurrentUser::class);
        $email = $this->inviteEmail;

        // The parked access roles are a grant like any other, just deferred: gate them
        // HERE, where there is a form to report on. By acceptance time the only place
        // left to refuse is a redirect-only controller with nowhere to say why.
        $selectedRoles = array_values(array_intersect($this->inviteAccessRoles, $this->validAccessRoleIds()));
        $refusal = app(SodGuard::class)->refuseSet($this->orgId(), $selectedRoles);

        if ($refusal !== null) {
            $this->addError('inviteAccessRoles', $refusal->message());

            return;
        }

        // Create a PENDING invitation — membership is granted only when the
        // invitee accepts via the emailed token. No one is added without consent.
        $pending = $invitations->invite($me->organizationId() ?? '', $email, $role, invitedBy: $me->id());

        Mail::to($email)->send(new InvitationMail(
            organization: $me->organization()->name ?? 'your team',
            inviter: $me->name(),
            url: $links->route('invitation.accept', $pending->token),
        ));

        // Park the chosen access roles for this email — applied on acceptance, so
        // there's no separate assignment step after they join.
        foreach ($selectedRoles as $roleId) {
            InvitationRoleGrant::query()->firstOrCreate([
                'organization_id' => $this->orgId(),
                'email' => $email,
                'role_id' => $roleId,
            ]);
        }

        $this->reset('inviteEmail', 'inviting', 'inviteAccessRoles');
        $this->inviteRole = 'member';
        $this->dispatch('toast', message: 'Invitation sent to '.$email.'.');
    }

    /**
     * Ids of the access roles a member may hold in this org (org-wide + this org's
     * app-declared roles) — the allow-list for both the invite picker and manage.
     *
     * @return list<string>
     */
    private function validAccessRoleIds(): array
    {
        $orgId = $this->orgId();
        $clientIds = Client::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
            ->pluck('client_id');

        /** @var list<string> $ids */
        $ids = Role::query()
            ->where(function ($q) use ($orgId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $orgId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->pluck('id')
            ->all();

        return $ids;
    }

    public function revokeInvitation(string $id, Invitations $invitations): void
    {
        $this->authorizeAdmin();
        $invitations->revoke($this->orgId(), $id);
        $this->dispatch('toast', message: 'Invitation revoked.');
    }

    public function setRole(string $userId, string $role, Memberships $memberships): void
    {
        $this->authorizeAdmin();

        // Invoked from JS with the <select>'s value, so the role is untrusted and there
        // is no form field to report into: an unassignable or unknown role is refused
        // outright rather than coerced to a default.
        $next = OrgRoles::parse($role);

        if ($next === null) {
            return;
        }

        // Only an owner may grant the owner role, and only an owner may act on an
        // existing owner (an admin cannot demote the org's owner).
        abort_if($next === MembershipRole::Owner && ! app(CurrentUser::class)->isOwner(), 403);
        abort_if($this->isOwner($userId, $memberships) && ! app(CurrentUser::class)->isOwner(), 403);

        if ($this->refuseMembership($userId)) {
            return;
        }

        try {
            $memberships->changeRole($this->orgId(), $userId, $next);
        } catch (LastOwner) {
            // Surface as an announced error toast, NOT addError('inviteEmail', …): the
            // only @error('inviteEmail') sink lives inside the collapsed invite form, so
            // a roster-row guard would block silently — the UI looks broken and a screen
            // reader hears nothing. The toast is role=alert/assertive.
            $this->dispatch('toast', message: 'The organization must keep at least one owner.', severity: 'error');
        }
    }

    public function remove(string $userId, Memberships $memberships): void
    {
        $this->authorizeAdmin();

        // Removing the membership without removing the account member leaves an account
        // member with no place in the organization their account owns — which is the
        // state the 2026_08_05_000200 backfill existed to repair.
        if ($this->refuseMembership($userId)) {
            return;
        }

        if ($userId === app(CurrentUser::class)->id()) {
            $this->dispatch('toast', message: 'You cannot remove yourself.', severity: 'error');

            return;
        }

        // Only an owner may remove another owner.
        abort_if($this->isOwner($userId, $memberships) && ! app(CurrentUser::class)->isOwner(), 403);

        try {
            $memberships->remove($this->orgId(), $userId);
            $this->dispatch('toast', message: 'Member removed.');
        } catch (LastOwner) {
            $this->dispatch('toast', message: 'The organization must keep at least one owner.', severity: 'error');
        }
    }

    private function isOwner(string $userId, Memberships $memberships): bool
    {
        return $memberships->of($this->orgId(), $userId)?->role === MembershipRole::Owner;
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $me = app(CurrentUser::class);
        $subjects = app(Subjects::class);

        $page = app(Memberships::class)->paginateForOrganization($this->orgId());

        /** @var Collection<int, Membership> $pageMembers */
        $pageMembers = new Collection($page->items());

        /** @var list<string> $pageUserIds */
        $pageUserIds = $pageMembers->pluck('user_id')->all();

        // Batch-resolve THIS page's subjects in one query (findMany) instead of a
        // per-row find(); pagination keeps the roster query bounded regardless of org size.
        $subjectsById = $subjects->findMany($pageUserIds);

        $rows = $pageMembers
            /** @return array{id: string, role: MembershipRole, subject: Subject|null, joined: CarbonInterface|null} */
            ->map(function (Membership $m) use ($subjectsById): array {
                // The package's Membership model does not declare the Eloquent timestamp
                // columns as @property, so read created_at off the attribute bag and
                // narrow it here rather than trusting an undeclared property.
                $joined = $m->getAttribute('created_at');

                return [
                    'id' => $m->user_id,
                    'role' => $m->role,
                    'subject' => $subjectsById[$m->user_id] ?? null,
                    'joined' => $joined instanceof CarbonInterface ? $joined : null,
                ];
            });

        // Access roles assignable to people: org-wide roles + app-declared roles for
        // apps this org can use. Grouped so the picker reads clearly.
        $orgId = $this->orgId();
        $clientIds = Client::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
            ->pluck('client_id');

        $accessRoles = Role::query()
            ->where(function ($q) use ($orgId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $orgId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->orderBy('name')
            ->get();

        $appNames = Client::query()
            ->whereIn('client_id', $accessRoles->pluck('client_id')->filter()->unique())
            ->pluck('name', 'client_id');

        // userId => list of assigned role ids (org-scoped).
        $assignmentsByUser = RoleAssignment::query()
            ->where('organization_id', $orgId)
            ->whereIn('user_id', $pageUserIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->pluck('role_id')->all());

        // roleId => the permissions it grants, so the Manage drawer shows what each
        // role actually lets a member do — the "effective access across apps" view.
        $permsByRole = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $accessRoles->pluck('id'))
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name'])
            ->groupBy('role_id')
            ->map(fn ($group) => $group->pluck('name')->all());

        return [
            'me' => $me,
            'members' => $page,
            'rows' => new Collection($rows),
            'invitations' => $me->isAdmin() ? app(Invitations::class)->pending($this->orgId()) : collect(),
            'accessRoles' => $accessRoles,
            'accessRolesById' => $accessRoles->keyBy('id'),
            'appNames' => $appNames,
            'assignmentsByUser' => $assignmentsByUser,
            'permsByRole' => $permsByRole,
            'assignableRoles' => OrgRoles::assignable(),
        ];
    }

    /**
     * Whether this person's place in the acting organization is governed by an ACCOUNT
     * membership rather than by this page.
     *
     * An account IS an organization in the platform root, so on that host this page and
     * `/account-members` list the same people — but they write different columns.
     * `/account-members` writes `account_members.role` and syncs it onto the membership;
     * this page writes `memberships.role` and syncs nothing back. Ten guards on the
     * account plane still read the member row, so the two silently disagree the moment
     * either is used on somebody the other owns:
     *
     *  - re-role a Developer to Admin here and they gain the member roster, the account
     *    audit chain and billing — all three of which `MembershipRole::Developer` refuses,
     *    because "a leaked developer key must not enumerate the team";
     *  - re-role them to Member and the rail goes dark while `projects/create` and the
     *    environment handoff, which read the member row, still let them stand up an
     *    environment and open a live environment-admin session on a tenant host. A
     *    demotion that confirms itself and demotes nothing.
     *
     * So this page declines, and says where the roster actually lives. That is the honest
     * statement of the fold's direction: the account roster is ONE roster, and it is not
     * this one. The alternative — syncing back — would require deciding what
     * `MembershipRole::Member` and `Owner` mean on the account plane, which today is
     * "nothing" and "not assignable".
     */
    private function governedByAccount(string $userId): bool
    {
        return Membership::query()
            ->where('subject_id', $userId)
            ->whereHas('account', fn ($account) => $account->where('organization_id', $this->orgId()))
            ->exists();
    }

    private function refuseMembership(string $userId): bool
    {
        if (! $this->governedByAccount($userId)) {
            return false;
        }

        $this->dispatch(
            'toast',
            message: 'This person is a member of the account that owns this organization. Manage their role under Identity platform → Account members.',
            severity: 'error',
        );

        return true;
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <x-page-header title="Members" :help="\App\Platform\Help\HelpTopic::Members"
                   subtitle="Everyone who can sign in to this organization, and the invitations nobody has accepted yet.">
        @if ($me->isAdmin())
            <x-slot:actions>
                <button wire:click="$toggle('inviting')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Invite member</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-8 space-y-6">
    @if ($inviting && $me->isAdmin())
        <form wire:submit="invite" class="card p-4 space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]">
                    <label class="label" for="inviteEmail">Email address</label>
                    <input wire:model="inviteEmail" id="inviteEmail" type="email" class="input" placeholder="teammate@company.com" autofocus>
                    @error('inviteEmail') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="inviteRole">Console access</label>
                    <select wire:model="inviteRole" id="inviteRole" class="select">
                        @foreach ($assignableRoles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    @error('inviteRole') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($accessRoles->isNotEmpty())
                <div>
                    <span class="label">Access roles <span style="color:var(--muted);font-weight:400">— granted the moment they accept (optional)</span></span>
                    @foreach ($accessRoles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $group)
                        <p wire:key="rolegroup-{{ $groupKey }}" class="text-xs font-semibold uppercase mb-1.5 mt-1" style="color:var(--muted);letter-spacing:0.05em">{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}</p>
                        <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3 mb-2">
                            @foreach ($group as $r)
                                <label class="flex items-center gap-2 text-sm rounded-lg px-2.5 py-1.5 cursor-pointer" style="border:1px solid var(--border);background:var(--card)">
                                    <input type="checkbox" wire:model="inviteAccessRoles" value="{{ $r->id }}">
                                    <span class="min-w-0 flex-1 truncate" style="color:var(--foreground)">{{ $r->name }}</span>
                                    <span class="badge mono" style="font-size:10px">{{ $r->key ?? 'org' }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                    @error('inviteAccessRoles') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Send invite</button>
                <button type="button" wire:click="$set('inviting', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    @if ($me->isAdmin() && $invitations->isNotEmpty())
        <div class="cbx-panel overflow-hidden">
            <div class="cbx-panel-header">
                <h2 class="cbx-panel-title">Pending invitations</h2>
            </div>
            <ul>
                @foreach ($invitations as $invite)
                    <li wire:key="invite-{{ $invite->id }}" class="px-5 py-3 border-b flex items-center justify-between gap-4" style="border-color:var(--border)">
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ $invite->email }}</p>
                            <p class="text-xs" style="color:var(--muted-foreground)">Invited as {{ $invite->role->label() }} · expires {{ $invite->expires_at?->diffForHumans() }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Pending</span>
                            <button wire:click="revokeInvitation('{{ $invite->id }}')" wire:confirm="Revoke this invitation?"
                                    class="btn btn-danger btn-sm">Revoke</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">Member</th><th scope="col">Console access</th><th scope="col">Roles in your apps</th><th scope="col">Joined</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="member-{{ $row['id'] }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="cbx-avatar">
                                        {{ strtoupper(substr($row['subject']?->name ?? $row['subject']?->email ?? '?', 0, 1)) }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-medium truncate">{{ $row['subject']?->name ?? '—' }}</p>
                                        <p class="text-xs truncate" style="color:var(--muted-foreground)">{{ $row['subject']?->email ?? $row['id'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($me->isAdmin())
                                    <select class="select"
                                            aria-label="Role for {{ $row['subject']?->name ?? $row['subject']?->email ?? 'this member' }}"
                                            wire:change="setRole('{{ $row['id'] }}', $event.target.value)">
                                        @foreach ($assignableRoles as $role)
                                            <option value="{{ $role->value }}" @selected($row['role'] === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="cbx-pill">{{ $row['role']->label() }}</span>
                                @endif
                            </td>
                            <td>
                                @php $assigned = $assignmentsByUser[$row['id']] ?? []; @endphp
                                <div class="flex flex-wrap items-center gap-1">
                                    @forelse ($assigned as $rid)
                                        @php $r = $accessRolesById[$rid] ?? null; @endphp
                                        @if ($r)<span class="badge">{{ $r->name }}</span>@endif
                                    @empty
                                        @if ($me->isAdmin() && $accessRoles->isEmpty())
                                            <a href="{{ route('roles') }}" wire:navigate class="text-xs" style="color:var(--accent-strong)">No roles defined yet →</a>
                                        @else
                                            <span class="text-xs" style="color:var(--faint)">None</span>
                                        @endif
                                    @endforelse
                                    @if ($me->isAdmin() && $accessRoles->isNotEmpty())
                                        <button wire:click="toggleManage('{{ $row['id'] }}')" class="btn btn-ghost btn-sm" style="height:24px;padding:0 8px;font-size:11px">
                                            {{ $managingUserId === $row['id'] ? 'Done' : 'Manage' }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="text-sm mono" style="color:var(--muted-foreground)">{{ $row['joined']?->format('M j, Y') ?? '—' }}</td>
                            <td class="text-right">
                                @if ($me->isAdmin() && $row['id'] !== $me->id())
                                    @php
                                        $removeAction = "remove('{$row['id']}')";
                                        $memberLabel = $row['subject']?->email ?? $row['subject']?->name ?? $row['id'];
                                    @endphp
                                    <x-confirm-delete
                                        :name="$memberLabel"
                                        :action="$removeAction"
                                        label="Remove"
                                        consequence="They lose every role and application this organization grants them, immediately." />
                                @endif
                            </td>
                        </tr>
                        @if ($managingUserId === $row['id'] && $me->isAdmin())
                            <tr>
                                <td colspan="5" style="background:color-mix(in oklch, var(--secondary) 55%, transparent);padding:14px 20px">
                                    <p class="text-xs mb-3" style="color:var(--muted)">Access roles for <b style="color:var(--foreground)">{{ $row['subject']?->name ?? $row['subject']?->email ?? 'this member' }}</b> — these ride in the app tokens; the app enforces what each one can do.</p>
                                    @foreach ($accessRoles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $group)
                                        <p class="text-xs font-semibold uppercase mb-1.5 mt-1" style="color:var(--muted);letter-spacing:0.05em">{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}</p>
                                        <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3 mb-3">
                                            @foreach ($group as $r)
                                                @php $grants = $permsByRole[$r->id] ?? []; @endphp
                                                <label class="flex flex-col gap-1 text-sm rounded-lg px-2.5 py-1.5 cursor-pointer" style="border:1px solid var(--border);background:var(--card)" title="{{ implode(', ', $grants) }}">
                                                    <span class="flex items-center gap-2">
                                                        <input type="checkbox" @checked(in_array($r->id, $assigned, true)) wire:click="toggleRole('{{ $row['id'] }}', '{{ $r->id }}')">
                                                        <span class="min-w-0 flex-1 truncate" style="color:var(--foreground)">{{ $r->name }}</span>
                                                        <span class="badge mono" style="font-size:10px">{{ $r->key ?? 'org' }}</span>
                                                    </span>
                                                    <span class="text-xs truncate" style="color:var(--faint)">{{ count($grants) > 0 ? implode(' · ', array_slice($grants, 0, 4)).(count($grants) > 4 ? ' +'.(count($grants) - 4) : '') : 'No permissions' }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="text-center py-10" style="color:var(--muted-foreground)">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($members->hasPages())
                <div class="mt-4">{{ $members->links() }}</div>
            @endif
        </div>
    </div>
    </div>
</div>
