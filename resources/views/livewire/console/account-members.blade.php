<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use App\Platform\AccountCapabilities;
use App\Platform\Console\ConsoleScope;
use App\Platform\MailLinks;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Account members — the account's team, roles, per-environment access, and
 * invitations. Managing members requires a management role; everyone else sees a
 * read-only roster.
 */
new #[Layout('components.layouts.app', ['title' => 'Account members'])] class extends Component
{
    public string $inviteEmail = '';

    public string $inviteName = '';

    public string $inviteRole = 'developer';

    /** The member whose environment access is being edited, if any. */
    public ?string $editingAccessFor = null;

    public bool $accessAll = true;

    /** @var list<string> */
    public array $accessEnvIds = [];

    public function mount(ConsoleScope $scope): mixed
    {
        // The roster is PII — a Developer/Billing-only role may not read it.
        if ($scope->capabilities()?->canReadMembers() !== true) {
            return redirect()->route('projects');
        }

        return null;
    }

    public function invite(AccountAuth $auth, AccountMembers $members, AccountActivity $activity, MailLinks $links): void
    {
        $current = $auth->current();
        $account = $current?->account;

        if ($account === null || ! AccountCapabilities::of($current->role)->canManageMembers()) {
            return;
        }

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:190'],
            'inviteName' => ['nullable', 'string', 'max:120'],
            'inviteRole' => ['required', Rule::in(array_map(fn (AccountRole $r) => $r->value, AccountRole::assignable()))],
        ]);

        // findByEmail is GLOBAL — account-member emails are unique across every account
        // ("one email, one root login") — so the old message, "that email already belongs
        // to a member", let an admin of one account probe whether any address belonged to
        // ANOTHER. One message for both cases closes that: a member of THIS account is
        // already visible on the roster below, so nothing is lost to the person entitled
        // to know, and nothing is disclosed to the person who is not.
        //
        // A residual signal remains — the invitation fails, so the address exists
        // somewhere — and that is inherent to globally-unique emails, not something a
        // message can hide. Rate limiting and the audit trail are what bound it.
        if ($members->findByEmail($this->inviteEmail) !== null) {
            $this->addError('inviteEmail', 'That email cannot be invited to this account.');

            return;
        }

        $invited = $members->invite($account->id, $this->inviteEmail, AccountRole::from($this->inviteRole), trim($this->inviteName) ?: null);
        // MailLinks, not URL:: — an invitation is mailed, so its origin must come from
        // the deployment rather than from the Host header of whoever asked to send it.
        $url = $links->temporarySignedRoute('account.invite.accept', now()->addDays(7), ['member' => $invited->id]);
        Mail::to($invited->email)->send(new AccountInviteMail($account->name, $current->name ?? $current->email, $url));

        $activity->record($account->id, 'account.member_invited', $current->id,
            targetType: 'account_member', targetId: $invited->id,
            context: ['email' => $invited->email, 'role' => $this->inviteRole], request: request());

        $this->reset('inviteEmail', 'inviteName');
        $this->dispatch('toast', message: 'Invitation sent to '.$invited->email.'.');
    }

    public function changeRole(string $memberId, string $role, AccountAuth $auth, AccountActivity $activity, AccountMembers $members): void
    {
        $current = $auth->current();
        $target = $this->manageableTarget($memberId, $auth);
        $next = AccountRole::tryFrom($role);

        if ($current === null || $target === null || $next === null || ! in_array($next, AccountRole::assignable(), true)) {
            return;
        }

        // $target->id, not $memberId. Identical values today, and deliberately the
        // resolved one: the service takes a bare id and does its own global lookup, so
        // the only thing tying the write to this account is that the id came out of the
        // fenced query above.
        $members->setRole($target->id, $next);

        $activity->record($current->account_id, 'account.member_role_changed', $auth->id(),
            targetType: 'account_member', targetId: $target->id,
            context: ['role' => $next->value], request: request());

        $this->dispatch('toast', message: 'Role updated.');
    }

    public function removeMember(string $memberId, AccountAuth $auth, AccountActivity $activity, AccountMembers $members): void
    {
        $current = $auth->current();
        $target = $this->manageableTarget($memberId, $auth);

        if ($current === null || $target === null) {
            return;
        }

        if ($members->remove($target->id)) {
            $activity->record($current->account_id, 'account.member_removed', $auth->id(),
                targetType: 'account_member', targetId: $target->id, request: request());

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

        $target = $this->resolve($memberId, $current->account_id);

        $members->transferOwnership($current->account_id, $target->id);
        $this->dispatch('toast', message: 'Ownership transferred to '.($target->name ?? $target->email).'.');
    }

    public function manageAccess(string $memberId, AccountAuth $auth): void
    {
        $target = $this->manageableTarget($memberId, $auth);

        if ($target === null || ! $target->role->supportsEnvironmentScoping()) {
            return;
        }

        $this->editingAccessFor = $target->id;
        $this->accessAll = $target->all_environments;

        /** @var list<string> $envIds */
        $envIds = $target->environments()->pluck('environments.id')->all();
        $this->accessEnvIds = $envIds;
    }

    public function saveAccess(AccountAuth $auth, AccountMembers $members): void
    {
        if ($this->editingAccessFor === null) {
            return;
        }

        $target = $this->manageableTarget($this->editingAccessFor, $auth);

        if ($target === null) {
            return;
        }

        $members->setEnvironmentAccess($target->id, $this->accessAll, $this->accessEnvIds);
        $this->editingAccessFor = null;
        $this->dispatch('toast', message: 'Environment access updated.');
    }

    public function cancelAccess(): void
    {
        $this->editingAccessFor = null;
    }

    /** The target member IF the current member may manage it (not self, not the owner). */
    private function manageableTarget(string $memberId, AccountAuth $auth): ?AccountMember
    {
        $current = $auth->current();

        // Asked BEFORE the account fence, and answered silently: a member who may read
        // the roster but not manage it, or one naming themselves, is looking at a person
        // they can genuinely see. A 404 there would deny the existence of a row rendered
        // three lines above it on the same page.
        if ($current === null || ! AccountCapabilities::of($current->role)->canManageMembers() || $memberId === $current->id) {
            return null;
        }

        $target = $this->resolve($memberId, $current->account_id);

        return $target->role === AccountRole::Owner ? null : $target;
    }

    /**
     * The named member WITHIN this account, or 404.
     *
     * The account id is in the QUERY. `AccountMembers::find()` is deliberately global —
     * it is what resolves "which account is this person on" at the root — so a lookup on
     * the primary key alone spans every account on the install, and what stood between
     * an admin of one account and a member of another was an `if` afterwards. That
     * comparison did hold, but it is the same shape that shipped a cross-organization
     * IDOR on /governance/{campaign}: the predicate has to be in the query, or the next
     * caller added to the file is one that forgets to re-check.
     *
     * The FENCE STAYS HERE rather than moving into {@see AccountMembers}. That interface
     * is the root's answer to "which account is this email/subject on" — a question you
     * ask precisely because you do not have an account id yet — so an `$accountId`
     * parameter on `setRole()`/`remove()`/`setEnvironmentAccess()` would be a second copy
     * of this boundary in the one layer that cannot own it, and would still be taking the
     * value from the same caller. What the service is owed instead is an id it can trust,
     * which is why every call below passes `$target->id` and not the string off the wire.
     *
     * 404, not 403 — consistent with the rest of the console. A member of somebody
     * else's account is not a permission this person lacks; it is a row they have no
     * business learning exists.
     */
    private function resolve(string $memberId, string $accountId): AccountMember
    {
        $target = AccountMember::query()
            ->whereKey($memberId)
            ->where('account_id', $accountId)
            ->first();

        abort_if($target === null, 404);

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMembers $members, ConsoleScope $scope): array
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
            // Asked of the SCOPE, not of the member row, so the rail, this page's own
            // guard and the buttons it renders all answer from one place — and so a
            // person acting on somebody else's organization is refused here too, which
            // a bare role read on the member cannot express.
            'canManage' => $scope->capabilities()?->canManageMembers() === true,
            'isOwner' => $current?->role === AccountRole::Owner,
            'assignableRoles' => AccountRole::assignable(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Account members" subtitle="People who can administer this account, their roles, and which environments they reach." />

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @foreach ($members as $m)
            @php
                $isSelf = $current && $m->id === $current->id;
                $manageable = $canManage && ! $isSelf && $m->role !== \Cbox\Id\Platform\Enums\AccountRole::Owner;
                $scoped = $m->role->supportsEnvironmentScoping();
                // Built here rather than inline: a Blade `:attr` value cannot itself
                // contain the double quote the action string needs around the id.
                $makeOwnerAction = "makeOwner('{$m->id}')";
                $removeMemberAction = "removeMember('{$m->id}')";
            @endphp
            <div wire:key="member-{{ $m->id }}" class="p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center w-9 h-9 rounded-full text-sm font-semibold shrink-0" style="background:var(--surface-2);color:var(--muted)" aria-hidden="true">{{ strtoupper(substr($m->name ?? $m->email, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium truncate">{{ $m->name ?? $m->email }}</span>
                            @if ($isSelf)<span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent-strong)">You</span>@endif
                            @if ($m->status !== \Cbox\Id\Platform\Enums\AccountMemberStatus::Active)<span class="badge badge-warn">{{ $m->status->value }}</span>@endif
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
                                    <x-confirm-delete
                                        :name="$m->email"
                                        :action="$makeOwnerAction"
                                        label="Transfer ownership"
                                        verb="Hand this account to"
                                        trigger-class="cbx-row w-full"
                                        trigger-style="padding:8px 10px;border-radius:6px;font-size:13px"
                                        consequence="They become the account owner and you are demoted to admin. Only the new owner can hand it back." />
                                @endif
                                <x-confirm-delete
                                    :name="$m->email"
                                    :action="$removeMemberAction"
                                    label="Remove"
                                    verb="Remove"
                                    trigger-class="cbx-row w-full"
                                    trigger-style="padding:8px 10px;border-radius:6px;font-size:13px;color:var(--destructive)"
                                    consequence="They lose access to this account and every environment under it immediately." />
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
            <p class="mt-1 text-sm" style="color:var(--muted)">They'll get an email to set a password and join this account.</p>
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
