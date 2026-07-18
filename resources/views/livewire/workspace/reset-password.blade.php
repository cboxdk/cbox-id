<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Rules\NotBreached;
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

    public function mount(string $member, AccountMembers $members)
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
    }

    public function submit(AccountMembers $members, AccountAuth $auth): void
    {
        $this->validate([
            'password' => ['required', 'string', 'min:12', 'max:200', new NotBreached],
        ]);

        if (! $members->resetPassword($this->member, $this->password)) {
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
                <x-icon name="check" class="w-3.5 h-3.5" x-bind:style="pw.length >= 12 ? 'color:var(--success)' : ''" />
                <span x-bind:style="pw.length >= 12 ? 'color:var(--success)' : ''">At least 12 characters</span>
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
