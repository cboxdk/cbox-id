<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Rules\NotBreached;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Accept a workspace invitation: the invitee sets a password and is signed in. The
 * page is reached only via a signed URL (route middleware), and the member id is
 * #[Locked] so it can't be swapped after that signed load — an attacker can neither
 * forge the signature nor point the action at a different member.
 */
new #[Layout('components.layouts.auth', ['title' => 'Accept invitation'])] class extends Component
{
    #[Locked]
    public string $member = '';

    public string $password = '';

    public ?string $email = null;

    public ?string $accountName = null;

    public function mount(string $member, AccountMembers $members)
    {
        $this->member = $member;
        $invited = $members->find($member);

        // Already accepted, revoked, or unknown — the link is spent.
        if ($invited === null || $invited->status !== 'invited') {
            return redirect()->route('workspace.login')
                ->with('status', 'This invitation is no longer valid. Try signing in.');
        }

        $this->email = $invited->email;
        $this->accountName = $invited->account?->name;
    }

    public function accept(AccountMembers $members, AccountAuth $auth): void
    {
        $this->validate([
            'password' => ['required', 'string', 'min:12', 'max:200', new NotBreached],
        ]);

        // activate() is a no-op unless the member is still 'invited', so a replayed
        // or racing accept can never reset an active member's password.
        if (! $members->activate($this->member, $this->password)) {
            $this->redirect(route('workspace.login'), navigate: false);

            return;
        }

        $auth->establish($this->member);
        $this->redirect(route('workspace.home'), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Accept your invitation</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        Set a password to join
        <span class="font-medium" style="color:var(--foreground)">{{ $accountName ?? 'the workspace' }}</span>
        as <span class="font-medium" style="color:var(--foreground)">{{ $email }}</span>.
    </p>

    <form wire:submit="accept" class="mt-7 space-y-4">
        <input type="hidden" name="email" value="{{ $email }}" autocomplete="username">
        <div x-data="{ pw: '' }">
            <label class="label" for="password">Choose a password</label>
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

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="accept">
            <span wire:loading.remove wire:target="accept">Accept &amp; sign in</span>
            <span wire:loading wire:target="accept" class="inline-flex items-center gap-2"><span class="spinner"></span> Setting up…</span>
        </button>
    </form>
</div>
