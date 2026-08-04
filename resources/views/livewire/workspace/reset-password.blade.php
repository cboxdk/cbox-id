<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\CredentialVerdict;
use App\Platform\Enums\RefusedFactor;
use App\Platform\SsoRefusal;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Workspace reset-password — reached only via a signed link (route middleware),
 * with the member id #[Locked] so it can't be swapped after the signed load.
 * Resets an active member's password and signs them in.
 */
new #[Layout('components.layouts.auth', ['title' => 'Set a new password'])] class extends Component
{
    #[Locked]
    public string $member = '';

    public string $password = '';

    public ?string $email = null;

    public function mount(string $member, AccountMembers $members): mixed
    {
        $this->member = $member;
        $target = $members->find($member);

        // Reject an inactive member, or a link whose stamp is stale — i.e. one that
        // was already used (a reset bumped session_version) or superseded by a newer
        // link. This makes the link single-use even within its signed window.
        if ($target === null || ! $target->isActive() || $target->session_version !== request()->integer('v')) {
            return redirect()->route('workspace.login')
                ->with('status', 'This reset link is no longer valid. Try again.');
        }

        $this->email = $target->email;

        return null;
    }

    public function submit(AccountMembers $members, AccountAuth $auth): void
    {
        // The tenant's policy, not a number this file picked. It also covers the breach
        // screen, so NotBreached would only add a second, weaker opinion.
        $this->validate([
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($members->find($this->member)?->subject_id)],
        ]);

        if (! $members->resetPassword($this->member, $this->password)) {
            $this->redirect(route('workspace.login'), navigate: false);

            return;
        }

        // This door is the password door with an inbox in front of it: prove you can read
        // the mail, choose a credential, be signed in on it. Every argument for refusing a
        // password under a mandate applies here twice over, and this one skipped the check
        // entirely — a member of a mandating organization could reset their way in.
        //
        // After resetPassword() rather than at mount(). The reset ALSO revokes the member's
        // existing sessions, which is the point of it and must still happen; and the link
        // stays single-use either way, so a refusal cannot be replayed into a second try.
        if ($auth->admitsFactor($this->member) === CredentialVerdict::SsoRequired) {
            $subjectId = $auth->subjectFor($this->member);

            if ($subjectId !== null) {
                SsoRefusal::hold($subjectId, RefusedFactor::PasswordReset);
            }

            $this->redirect(route('workspace.login'), navigate: false);

            return;
        }

        $auth->establish($this->member);
        $this->redirect(route('workspace.home'), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Set a new password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Choose a new password for <span class="font-medium" style="color:var(--foreground)">{{ $email }}</span>.</p>

    <form wire:submit="submit" class="mt-7 space-y-4">
        <input type="hidden" name="email" value="{{ $email }}" autocomplete="username">
        <div x-data="{ pw: '' }">
            <label class="label" for="password">New password</label>
            <input wire:model="password" x-on:input="pw = $event.target.value"
                   id="password" name="password" type="password"
                   autocomplete="new-password" minlength="12"
                   class="input input-lg" placeholder="At least 12 characters" autofocus
                   aria-describedby="password-policy @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <div id="password-policy" class="mt-2 flex items-center gap-1.5 text-xs" style="color:var(--faint)">
                <x-icon name="check" class="w-3.5 h-3.5" x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''" />
                <span x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''">At least 12 characters</span>
                <span class="mx-1" aria-hidden="true">·</span>
                <span>checked against known breaches</span>
            </div>
            @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="submit">
            <span wire:loading.remove wire:target="submit">Set password &amp; sign in</span>
            <span wire:loading wire:target="submit" class="inline-flex items-center gap-2"><span class="spinner"></span> Saving…</span>
        </button>
    </form>
</div>
