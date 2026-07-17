<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Invitations;
use Illuminate\Support\Facades\DB;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Members'])] class extends Component
{
    #[Validate('required|email|max:190')]
    public string $inviteEmail = '';

    #[Validate('required|in:member,admin,owner')]
    public string $inviteRole = 'member';

    /** @var array<int, string> Access-role ids to grant the invitee on acceptance. */
    public array $inviteAccessRoles = [];

    public bool $inviting = false;

    /** The member whose access-roles panel is expanded, if any. */
    public ?string $managingUserId = null;

    public function toggleManage(string $userId): void
    {
        $this->authorizeAdmin();
        $this->managingUserId = $this->managingUserId === $userId ? null : $userId;
    }

    /** Assign or remove an org/app access-role for a member (manual grant). */
    public function toggleRole(string $userId, string $roleId, Roles $roles): void
    {
        $this->authorizeAdmin();

        $held = RoleAssignment::query()
            ->where('organization_id', $this->orgId())
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();

        if ($held) {
            $roles->unassign($this->orgId(), $userId, $roleId);
        } else {
            $roles->assign($this->orgId(), $userId, $roleId, GrantSource::Manual);
        }
    }

    public function invite(Invitations $invitations): void
    {
        $this->authorizeAdmin();
        $this->validate();

        // Only an owner may invite someone straight to owner.
        abort_if($this->inviteRole === 'owner' && ! app(CurrentUser::class)->isOwner(), 403);

        $me = app(CurrentUser::class);
        $email = $this->inviteEmail;

        // Create a PENDING invitation — membership is granted only when the
        // invitee accepts via the emailed token. No one is added without consent.
        $pending = $invitations->invite($me->organizationId() ?? '', $email, $this->inviteRole, invitedBy: $me->id());

        Mail::to($email)->send(new InvitationMail(
            organization: $me->organization()?->name ?? 'your team',
            inviter: $me->name(),
            url: route('invitation.accept', $pending->token),
        ));

        // Park the chosen access roles for this email — applied on acceptance, so
        // there's no separate assignment step after they join.
        $validRoleIds = $this->validAccessRoleIds();
        foreach (array_values(array_intersect($this->inviteAccessRoles, $validRoleIds)) as $roleId) {
            InvitationRoleGrant::query()->firstOrCreate([
                'organization_id' => $this->orgId(),
                'email' => $email,
                'role_id' => $roleId,
            ]);
        }

        $this->reset('inviteEmail', 'inviting', 'inviteAccessRoles');
        $this->inviteRole = 'member';
        session()->flash('status', 'Invitation sent to '.$email.'.');
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

        return Role::query()
            ->where(function ($q) use ($orgId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $orgId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->pluck('id')
            ->all();
    }

    public function revokeInvitation(string $id, Invitations $invitations): void
    {
        $this->authorizeAdmin();
        $invitations->revoke($this->orgId(), $id);
        session()->flash('status', 'Invitation revoked.');
    }

    public function setRole(string $userId, string $role, Memberships $memberships): void
    {
        $this->authorizeAdmin();

        if (! in_array($role, ['member', 'admin', 'owner'], true)) {
            return;
        }

        // Only an owner may grant the owner role, and only an owner may act on an
        // existing owner (an admin cannot demote the org's owner).
        abort_if($role === 'owner' && ! app(CurrentUser::class)->isOwner(), 403);
        abort_if($this->isOwner($userId, $memberships) && ! app(CurrentUser::class)->isOwner(), 403);

        try {
            $memberships->changeRole($this->orgId(), $userId, $role);
        } catch (\Cbox\Id\Organization\Exceptions\LastOwner) {
            $this->addError('inviteEmail', 'The organization must keep at least one owner.');
        }
    }

    public function remove(string $userId, Memberships $memberships): void
    {
        $this->authorizeAdmin();

        if ($userId === app(CurrentUser::class)->id()) {
            $this->addError('inviteEmail', 'You cannot remove yourself.');

            return;
        }

        // Only an owner may remove another owner.
        abort_if($this->isOwner($userId, $memberships) && ! app(CurrentUser::class)->isOwner(), 403);

        try {
            $memberships->remove($this->orgId(), $userId);
            session()->flash('status', 'Member removed.');
        } catch (\Cbox\Id\Organization\Exceptions\LastOwner) {
            $this->addError('inviteEmail', 'The organization must keep at least one owner.');
        }
    }

    private function isOwner(string $userId, Memberships $memberships): bool
    {
        return $memberships->of($this->orgId(), $userId)?->role === 'owner';
    }

    public function with(): array
    {
        $me = app(CurrentUser::class);
        $subjects = app(Subjects::class);

        $rows = app(Memberships::class)->forOrganization($this->orgId())
            ->map(fn ($m): array => [
                'id' => $m->user_id,
                'role' => $m->role,
                'subject' => $subjects->find($m->user_id),
                'joined' => $m->created_at,
            ]);

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
            'rows' => new Collection($rows),
            'invitations' => $me->isAdmin() ? app(Invitations::class)->pending($this->orgId()) : collect(),
            'accessRoles' => $accessRoles,
            'accessRolesById' => $accessRoles->keyBy('id'),
            'appNames' => $appNames,
            'assignmentsByUser' => $assignmentsByUser,
            'permsByRole' => $permsByRole,
        ];
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
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Organization</p>
            <h1 class="cbx-page-title">Members</h1>
            <p class="cbx-page-desc">People with access to this organization.</p>
        </div>
        @if ($me->isAdmin())
            <div class="flex items-center gap-2">
                <button wire:click="$toggle('inviting')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Invite member</button>
            </div>
        @endif
    </div>

    <div class="mt-8 space-y-6">
    @if ($inviting && $me->isAdmin())
        <form wire:submit="invite" class="card p-4 space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]">
                    <label class="label" for="inviteEmail">Email address</label>
                    <input wire:model="inviteEmail" id="inviteEmail" type="email" class="input" placeholder="teammate@company.com" autofocus>
                    @error('inviteEmail') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="inviteRole">Workspace access</label>
                    <select wire:model="inviteRole" id="inviteRole" class="select">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                        <option value="owner">Owner</option>
                    </select>
                </div>
            </div>

            @if ($accessRoles->isNotEmpty())
                <div>
                    <span class="label">Access roles <span style="color:var(--muted);font-weight:400">— granted the moment they accept (optional)</span></span>
                    @foreach ($accessRoles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $group)
                        <p class="text-xs font-semibold uppercase mb-1.5 mt-1" style="color:var(--muted);letter-spacing:0.05em">{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}</p>
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
                <h3 class="cbx-panel-title">Pending invitations</h3>
            </div>
            <ul>
                @foreach ($invitations as $invite)
                    <li class="px-5 py-3 border-b flex items-center justify-between gap-4" style="border-color:var(--border)">
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ $invite->email }}</p>
                            <p class="text-xs" style="color:var(--muted-foreground)">Invited as {{ ucfirst($invite->role) }} · expires {{ $invite->expires_at?->diffForHumans() }}</p>
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
                    <tr><th scope="col">Member</th><th scope="col">Workspace access</th><th scope="col">Access roles</th><th scope="col">Joined</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
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
                                        @foreach (['member' => 'Member', 'admin' => 'Admin', 'owner' => 'Owner'] as $val => $label)
                                            <option value="{{ $val }}" @selected($row['role'] === $val)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="cbx-pill">{{ ucfirst($row['role']) }}</span>
                                @endif
                            </td>
                            <td>
                                @php $assigned = $assignmentsByUser[$row['id']] ?? []; @endphp
                                <div class="flex flex-wrap items-center gap-1">
                                    @forelse ($assigned as $rid)
                                        @php $r = $accessRolesById[$rid] ?? null; @endphp
                                        @if ($r)<span class="badge">{{ $r->name }}</span>@endif
                                    @empty
                                        <span class="text-xs" style="color:var(--faint)">None</span>
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
                                    <button wire:click="remove('{{ $row['id'] }}')"
                                            wire:confirm="Remove this member from the organization?"
                                            class="btn btn-danger btn-sm">Remove</button>
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
        </div>
    </div>
    </div>
</div>
