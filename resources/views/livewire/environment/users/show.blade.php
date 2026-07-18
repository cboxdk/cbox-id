<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users › detail. The full, deep-linkable lifecycle for
 * one end-user: profile, email verification, password reset, activate/suspend/delete,
 * organization memberships and support impersonation.
 *
 * Every mutation re-resolves the target within THIS environment (the User model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default).
 */
new #[Layout('components.layouts.environment')] class extends Component
{
    public string $userId = '';

    public string $editName = '';

    public string $editEmail = '';

    public string $assignOrgId = '';

    public string $assignRole = 'member';

    public function mount(string $user): void
    {
        $model = User::query()->whereKey($user)->first();
        abort_if($model === null, 404);

        $this->userId = $model->id;
        $this->editName = $model->name ?? '';
        $this->editEmail = $model->email;
    }

    private function user(): User
    {
        $model = User::query()->whereKey($this->userId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function saveProfile(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'editName' => ['nullable', 'string', 'max:190'],
            'editEmail' => ['required', 'email', 'max:190'],
        ]);

        $emailChanged = mb_strtolower($data['editEmail']) !== mb_strtolower($user->email);

        if ($emailChanged && User::query()->where('email', $data['editEmail'])->whereKeyNot($user->id)->exists()) {
            $this->addError('editEmail', 'Another user already uses that email in this environment.');

            return;
        }

        $user->name = trim($data['editName']) !== '' ? trim($data['editName']) : null;
        if ($emailChanged) {
            // A changed email is unverified until re-confirmed — never silently trust it.
            $user->email = $data['editEmail'];
            $user->email_verified_at = null;
        }
        $user->save();

        session()->flash('status', 'Profile updated.');
    }

    public function suspend(Subjects $subjects): void
    {
        $subjects->deactivate($this->user()->id);
        session()->flash('status', 'User deactivated — they can no longer sign in.');
    }

    public function reactivate(Subjects $subjects): void
    {
        $subjects->reactivate($this->user()->id);
        session()->flash('status', 'User reactivated.');
    }

    public function deleteUser(Memberships $memberships): mixed
    {
        $user = $this->user();

        try {
            foreach ($memberships->forUser($user->id) as $membership) {
                $memberships->remove($membership->organization_id, $user->id);
            }
            $user->delete();
        } catch (\Throwable) {
            session()->flash('status', 'Could not delete this user (they still have linked records) — deactivate instead.');

            return null;
        }

        session()->flash('status', 'User deleted.');

        return $this->redirectRoute('environment.users', navigate: true);
    }

    public function sendPasswordReset(PasswordReset $resets): void
    {
        $user = $this->user();

        $token = $resets->request($user->email);
        if (is_string($token)) {
            Mail::to($user->email)->send(new PasswordResetMail(route('password.reset', $token)));
        }

        session()->flash('status', 'Password reset email sent to '.$user->email.'.');
    }

    public function resendVerification(EmailVerification $verification): void
    {
        $user = $this->user();
        if ($user->email_verified_at !== null) {
            return;
        }

        $token = $verification->issue($user->id, $user->email);
        Mail::to($user->email)->send(new EmailVerificationMail(route('verification.verify', $token)));

        session()->flash('status', 'Verification email sent to '.$user->email.'.');
    }

    public function markVerified(Subjects $subjects): void
    {
        $user = $this->user();
        $subjects->markEmailVerified($user->id, $user->email);
        session()->flash('status', 'Email marked as verified.');
    }

    public function assignOrg(Memberships $memberships): void
    {
        $user = $this->user();

        $this->validate([
            'assignOrgId' => ['required', 'string'],
            'assignRole' => ['required', 'in:member,admin,owner'],
        ]);

        if (Organization::query()->whereKey($this->assignOrgId)->doesntExist()) {
            $this->addError('assignOrgId', 'That organization is not in this environment.');

            return;
        }

        if ($memberships->of($this->assignOrgId, $user->id) !== null) {
            $this->addError('assignOrgId', 'The user is already a member of that organization.');

            return;
        }

        $memberships->add($this->assignOrgId, $user->id, $this->assignRole);

        $this->assignOrgId = '';
        $this->assignRole = 'member';
        session()->flash('status', 'User added to the organization.');
    }

    public function changeMembershipRole(string $orgId, string $role, Memberships $memberships): void
    {
        if (in_array($role, ['member', 'admin', 'owner'], true)) {
            $memberships->changeRole($orgId, $this->user()->id, $role);
            session()->flash('status', 'Role updated.');
        }
    }

    public function removeMembership(string $orgId, Memberships $memberships): void
    {
        $memberships->remove($orgId, $this->user()->id);
        session()->flash('status', 'Removed from the organization.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Memberships $memberships): array
    {
        $user = $this->user();

        /** @var \Illuminate\Support\Collection<string, string> $orgNames */
        $orgNames = Organization::query()->orderBy('name')->pluck('name', 'id');

        $rows = [];
        $impersonatable = [];
        foreach ($memberships->forUser($user->id) as $m) {
            $rows[] = ['org' => $m->organization_id, 'orgName' => $orgNames[$m->organization_id] ?? $m->organization_id, 'role' => $m->role];
            if (! in_array($m->role, ['owner', 'admin'], true)) {
                $impersonatable[] = ['org' => $m->organization_id, 'orgName' => $orgNames[$m->organization_id] ?? $m->organization_id];
            }
        }

        return [
            'user' => $user,
            'allOrgs' => $orgNames,
            'memberships' => $rows,
            'impersonatableOrgs' => $impersonatable,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.users') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Users</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $user->name ?? $user->email }}</h1>
            @unless ($user->email_verified_at)
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Unverified</span>
            @endunless
            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $user->status->value }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $user->id }}</p>
    </div>

    {{-- Profile --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Profile</p>
        <form wire:submit="saveProfile" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <label class="label" for="editName">Name</label>
                <input wire:model="editName" id="editName" type="text" class="input" placeholder="Full name">
                @error('editName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="editEmail">Email</label>
                <input wire:model="editEmail" id="editEmail" type="email" class="input">
                @error('editEmail') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0 self-end">Save</button>
        </form>
    </div>

    {{-- Security & lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Security &amp; lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="sendPasswordReset">Send password reset</button>
            @unless ($user->email_verified_at)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resendVerification">Resend verification</button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="markVerified">Mark verified</button>
            @endunless
            @if ($user->status === UserStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="suspend" wire:confirm="Deactivate this user? They can no longer sign in.">Deactivate</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="reactivate">Reactivate</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteUser" wire:confirm="Permanently delete this user and their memberships? This cannot be undone.">Delete user</button>
        </div>
    </div>

    {{-- Organizations --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Organizations</p>
        <div class="mt-4 space-y-2">
            @forelse ($memberships as $m)
                <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="mem-{{ $m['org'] }}">
                    <a href="{{ route('environment.organizations.show', $m['org']) }}" class="min-w-0 flex-1 truncate text-sm font-medium" style="color:var(--accent)">{{ $m['orgName'] }}</a>
                    <select class="select" style="width:auto" wire:change="changeMembershipRole('{{ $m['org'] }}', $event.target.value)">
                        @foreach (['member' => 'Member', 'admin' => 'Admin', 'owner' => 'Owner'] as $val => $lbl)
                            <option value="{{ $val }}" @selected($m['role'] === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMembership('{{ $m['org'] }}')" wire:confirm="Remove from this organization?">Remove</button>
                </div>
            @empty
                <p class="text-sm" style="color:var(--muted)">Not a member of any organization.</p>
            @endforelse
        </div>
        <form wire:submit="assignOrg" class="mt-4 grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start">
            <div>
                <select wire:model="assignOrgId" class="select">
                    <option value="">Add to organization…</option>
                    @foreach ($allOrgs as $orgId => $orgName)
                        <option value="{{ $orgId }}">{{ $orgName }}</option>
                    @endforeach
                </select>
                @error('assignOrgId') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <select wire:model="assignRole" class="select">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="owner">Owner</option>
            </select>
            <button type="submit" class="btn btn-primary shrink-0">Add</button>
        </form>
    </div>

    {{-- Support impersonation (full request — changes the session) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Support impersonation</p>
        @if ($impersonatableOrgs !== [])
            <form method="POST" action="{{ route('environment.impersonate', $user->id) }}" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
                @csrf
                <select name="organization" class="select" required aria-label="Organization">
                    @foreach ($impersonatableOrgs as $io)
                        <option value="{{ $io['org'] }}">{{ $io['orgName'] }}</option>
                    @endforeach
                </select>
                <input name="reason" type="text" class="input" placeholder="Reason (required)" maxlength="200" required aria-label="Reason">
                <button type="submit" class="btn btn-ghost btn-sm shrink-0" onclick="return confirm('Step into this user\'s session for support? It is time-boxed and fully audited.')">Impersonate</button>
            </form>
            <p class="mt-2 text-xs" style="color:var(--faint)">Time-boxed to 30 minutes and recorded on the audit trail.</p>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">Add the user to an organization as a member to enable support impersonation (owners and admins can't be impersonated).</p>
        @endif
    </div>
</div>
