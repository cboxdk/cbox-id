<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Members — the account's team, roles, per-environment access, and
 * invitations. Managing members requires a management role; everyone else sees a
 * read-only roster.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Members'])] class extends Component
{
    public string $inviteEmail = '';

    public string $inviteName = '';

    public string $inviteRole = 'developer';

    /** The member whose environment access is being edited, if any. */
    public ?string $editingAccessFor = null;

    public bool $accessAll = true;

    /** @var list<string> */
    public array $accessEnvIds = [];

    public function mount(AccountAuth $auth)
    {
        // The roster is PII — a Developer/Billing-only role may not read it.
        if (! ($auth->current()?->role->canReadMembers() ?? false)) {
            return redirect()->route('workspace.home');
        }
    }

    public function invite(AccountAuth $auth, AccountMembers $members, AccountActivity $activity): void
    {
        $current = $auth->current();
        $account = $current?->account;

        if ($account === null || ! $current->role->canManageMembers()) {
            return;
        }

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:190'],
            'inviteName' => ['nullable', 'string', 'max:120'],
            'inviteRole' => ['required', Rule::in(array_map(fn (AccountRole $r) => $r->value, AccountRole::assignable()))],
        ]);

        if ($members->findByEmail($this->inviteEmail) !== null) {
            $this->addError('inviteEmail', 'That email already belongs to a member.');

            return;
        }

        $invited = $members->invite($account->id, $this->inviteEmail, AccountRole::from($this->inviteRole), trim($this->inviteName) ?: null);
        $url = URL::temporarySignedRoute('workspace.invite.accept', now()->addDays(7), ['member' => $invited->id]);
        Mail::to($invited->email)->send(new AccountInviteMail($account->name, $current->name ?? $current->email, $url));

        $activity->record($account->id, 'account.member_invited', $current->id,
            targetType: 'account_member', targetId: $invited->id,
            context: ['email' => $invited->email, 'role' => $this->inviteRole], request: request());

        $this->reset('inviteEmail', 'inviteName');
        $this->dispatch('toast', message: 'Invitation sent to '.$invited->email.'.');
    }

    public function changeRole(string $memberId, string $role, AccountAuth $auth, AccountMembers $members, AccountActivity $activity): void
    {
        $target = $this->manageableTarget($memberId, $auth, $members);
        $next = AccountRole::tryFrom($role);

        if ($target === null || $next === null || ! in_array($next, AccountRole::assignable(), true)) {
            return;
        }

        $members->setRole($memberId, $next);

        $activity->record($auth->current()->account_id, 'account.member_role_changed', $auth->id(),
            targetType: 'account_member', targetId: $memberId,
            context: ['role' => $next->value], request: request());

        $this->dispatch('toast', message: 'Role updated.');
    }

    public function removeMember(string $memberId, AccountAuth $auth, AccountMembers $members, AccountActivity $activity): void
    {
        if ($this->manageableTarget($memberId, $auth, $members) === null) {
            return;
        }

        if ($members->remove($memberId)) {
            $activity->record($auth->current()->account_id, 'account.member_removed', $auth->id(),
                targetType: 'account_member', targetId: $memberId, request: request());

            $this->dispatch('toast', message: 'Member removed.');
        }
    }

    /** Transfer ownership to another member — current owner only. */
    public function makeOwner(string $memberId, AccountAuth $auth, AccountMembers $members): void
    {
        $current = $auth->current();

        if ($current === null || $current->role !== AccountRole::Owner || $memberId === $current->id) {
            return;
        }

        $target = $members->find($memberId);

        if ($target === null || $target->account_id !== $current->account_id) {
            return;
        }

        $members->transferOwnership($current->account_id, $memberId);
        $this->dispatch('toast', message: 'Ownership transferred to '.($target->name ?? $target->email).'.');
    }

    public function manageAccess(string $memberId, AccountAuth $auth, AccountMembers $members): void
    {
        $target = $this->manageableTarget($memberId, $auth, $members);

        if ($target === null || ! $target->role->supportsEnvironmentScoping()) {
            return;
        }

        $this->editingAccessFor = $memberId;
        $this->accessAll = $target->all_environments;
        $this->accessEnvIds = $target->environments()->pluck('environments.id')->all();
    }

    public function saveAccess(AccountAuth $auth, AccountMembers $members): void
    {
        if ($this->editingAccessFor === null || $this->manageableTarget($this->editingAccessFor, $auth, $members) === null) {
            return;
        }

        $members->setEnvironmentAccess($this->editingAccessFor, $this->accessAll, $this->accessEnvIds);
        $this->editingAccessFor = null;
        $this->dispatch('toast', message: 'Environment access updated.');
    }

    public function cancelAccess(): void
    {
        $this->editingAccessFor = null;
    }

    /** The target member IF the current member may manage it (not self, not the owner). */
    private function manageableTarget(string $memberId, AccountAuth $auth, AccountMembers $members): ?object
    {
        $current = $auth->current();

        if ($current === null || ! $current->role->canManageMembers() || $memberId === $current->id) {
            return null;
        }

        $target = $members->find($memberId);

        if ($target === null || $target->account_id !== $current->account_id || $target->role === AccountRole::Owner) {
            return null;
        }

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMembers $members): array
    {
        $current = $auth->current();
        $account = $current?->account;

        /** @var Collection<int, \Cbox\Id\Platform\Models\AccountMember> $roster */
        $roster = $account === null ? collect() : $members->forAccount($account->id);
        $environments = $account === null ? collect() : Environment::query()->where('account_id', $account->id)->orderBy('created_at')->get();

        return [
            'current' => $current,
            'members' => $roster,
            'environments' => $environments,
            'canManage' => $current?->role->canManageMembers() ?? false,
            'isOwner' => $current?->role === AccountRole::Owner,
            'assignableRoles' => AccountRole::assignable(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Members" subtitle="People who can administer this account, their roles, and which environments they reach." />

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @foreach ($members as $m)
            @php
                $isSelf = $current && $m->id === $current->id;
                $manageable = $canManage && ! $isSelf && $m->role !== \Cbox\Id\Platform\Enums\AccountRole::Owner;
                $scoped = $m->role->supportsEnvironmentScoping();
            @endphp
            <div class="p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center w-9 h-9 rounded-full text-sm font-semibold shrink-0" style="background:var(--surface-2);color:var(--muted)" aria-hidden="true">{{ strtoupper(substr($m->name ?? $m->email, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium truncate">{{ $m->name ?? $m->email }}</span>
                            @if ($isSelf)<span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">You</span>@endif
                            @if ($m->status !== 'active')<span class="badge badge-warn">{{ $m->status }}</span>@endif
                        </div>
                        <p class="text-sm truncate" style="color:var(--muted)">{{ $m->email }}</p>
                    </div>

                    @if ($manageable)
                        <select class="input shrink-0" style="width:auto;padding-top:6px;padding-bottom:6px"
                                wire:change="changeRole('{{ $m->id }}', $event.target.value)" aria-label="Role for {{ $m->email }}">
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
                                    <button type="button" class="cbx-row w-full" style="padding:8px 10px;border-radius:6px;font-size:13px" wire:click="makeOwner('{{ $m->id }}')" wire:confirm="Transfer ownership to {{ $m->email }}? You will become an admin.">Transfer ownership</button>
                                @endif
                                <button type="button" class="cbx-row w-full" style="padding:8px 10px;border-radius:6px;font-size:13px;color:var(--destructive)" wire:click="removeMember('{{ $m->id }}')" wire:confirm="Remove {{ $m->email }} from this account?">Remove</button>
                            </div>
                        </div>
                    @else
                        <span class="badge shrink-0">{{ $m->role->label() }}</span>
                    @endif
                </div>

                {{-- Environment-access summary + inline editor for scoped roles. --}}
                @if ($scoped)
                    <div class="mt-2 ml-12 text-xs" style="color:var(--faint)">
                        @if ($m->all_environments)
                            Access to all environments
                        @else
                            Access to {{ $m->environments()->count() }} of {{ $environments->count() }} environments
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

    @if ($canManage)
        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm font-medium">Invite a teammate</p>
            <p class="mt-1 text-sm" style="color:var(--muted)">They'll get an email to set a password and join this workspace.</p>
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
