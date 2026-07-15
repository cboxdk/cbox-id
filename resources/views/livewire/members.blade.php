<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
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

    public bool $inviting = false;

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

        $this->reset('inviteEmail', 'inviting');
        $this->inviteRole = 'member';
        session()->flash('status', 'Invitation sent to '.$email.'.');
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

        return [
            'me' => $me,
            'rows' => new Collection($rows),
            'invitations' => $me->isAdmin() ? app(Invitations::class)->pending($this->orgId()) : collect(),
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
        <form wire:submit="invite" class="card p-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="inviteEmail">Email address</label>
                <input wire:model="inviteEmail" id="inviteEmail" type="email" class="input" placeholder="teammate@company.com" autofocus>
                @error('inviteEmail') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="inviteRole">Role</label>
                <select wire:model="inviteRole" id="inviteRole" class="select">
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                    <option value="owner">Owner</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Send invite</button>
            <button type="button" wire:click="$set('inviting', false)" class="btn btn-ghost">Cancel</button>
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
                    <tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col">Joined</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
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
                            <td class="text-sm mono" style="color:var(--muted-foreground)">{{ $row['joined']?->format('M j, Y') ?? '—' }}</td>
                            <td class="text-right">
                                @if ($me->isAdmin() && $row['id'] !== $me->id())
                                    <button wire:click="remove('{{ $row['id'] }}')"
                                            wire:confirm="Remove this member from the organization?"
                                            class="btn btn-danger btn-sm">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-10" style="color:var(--muted-foreground)">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>
