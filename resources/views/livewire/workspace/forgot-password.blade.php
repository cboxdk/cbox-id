<?php

declare(strict_types=1);

use App\Mail\WorkspacePasswordResetMail;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace forgot-password — sends a signed reset link. The response is always
 * neutral ("if that email has a workspace…") so it never reveals whether an email
 * is a real account member (no enumeration).
 */
new #[Layout('components.layouts.auth', ['title' => 'Reset password'])] class extends Component
{
    public string $email = '';

    public function request(AccountMembers $members): void
    {
        $this->validate(['email' => ['required', 'email', 'max:190']]);

        $key = 'workspace-reset|'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 300);

        $member = $members->findByEmail($this->email);

        // Only send to an active member; an invited one must still accept.
        if ($member !== null && $member->isActive()) {
            // Bind the link to the member's current security stamp: using it bumps
            // the stamp, so the link (and any earlier one) is single-use.
            $url = URL::temporarySignedRoute('workspace.password.reset', now()->addHour(), [
                'member' => $member->id,
                'v' => $member->session_version,
            ]);
            Mail::to($member->email)->send(new WorkspacePasswordResetMail($url));
        }

        session()->flash('status', 'If that email has a workspace, we\'ve sent a reset link.');
        $this->redirect(route('workspace.login'), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Reset your password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Enter your work email and we'll send a link to set a new password.</p>

    <form wire:submit="request" class="mt-7 space-y-4">
        <div>
            <label class="label" for="email">Work email</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" class="input input-lg" placeholder="you@yourco.example" autofocus>
            @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="request">
            <span wire:loading.remove wire:target="request">Send reset link</span>
            <span wire:loading wire:target="request" class="inline-flex items-center gap-2"><span class="spinner"></span> Sending…</span>
        </button>
    </form>

    <p class="mt-8 text-sm text-center" style="color:var(--muted)">
        Remembered it? <a href="{{ route('workspace.login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Sign in</a>
    </p>
</div>
