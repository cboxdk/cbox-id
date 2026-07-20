<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace two-factor challenge. Sits between password and a full session: it is
 * reachable only with a pending marker (set by AccountAuth::attempt on a member
 * with a confirmed factor) and grants nothing on its own.
 */
new #[Layout('components.layouts.auth', ['title' => 'Two-factor authentication'])] class extends Component
{
    public string $code = '';

    public bool $recovery = false;

    public function mount(AccountAuth $auth)
    {
        if ($auth->pendingMemberId() === null) {
            return redirect()->route('workspace.login');
        }
    }

    public function verify(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $memberId = $auth->pendingMemberId();

        if ($memberId === null) {
            $this->redirect(route('workspace.login'), navigate: false);

            return;
        }

        $key = 'workspace-mfa|'.$memberId.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $this->validate(['code' => ['required', 'string', 'max:64']]);

        $ok = $this->recovery
            ? $mfa->verifyRecoveryCode($memberId, $this->code)
            : $mfa->verifyTotp($memberId, $this->code);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            $this->addError('code', $this->recovery ? 'That recovery code is not valid.' : 'That code is not valid.');

            return;
        }

        RateLimiter::clear($key);
        $auth->establish($memberId);
        // Honor where they were headed before the challenge (e.g. the /open/{env}
        // handoff mint), else the workspace home.
        $this->redirect(session()->pull('url.intended', route('workspace.home')), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Two-factor authentication</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        {{ $recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.' }}
    </p>

    <form wire:submit="verify" class="mt-7 space-y-4">
        <div>
            <label class="label" for="code">{{ $recovery ? 'Recovery code' : 'Authentication code' }}</label>
            <input wire:model="code" id="code" type="text"
                   inputmode="{{ $recovery ? 'text' : 'numeric' }}" autocomplete="one-time-code"
                   class="input input-lg" placeholder="{{ $recovery ? 'xxxx-xxxx' : '123456' }}" autofocus>
            @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="verify">
            <span wire:loading.remove wire:target="verify">Verify</span>
            <span wire:loading wire:target="verify" class="inline-flex items-center gap-2"><span class="spinner"></span> Verifying…</span>
        </button>
    </form>

    <p class="mt-6 text-sm text-center" style="color:var(--muted)">
        <button type="button" wire:click="$toggle('recovery')" class="underline underline-offset-2" style="color:var(--accent)">
            {{ $recovery ? 'Use your authenticator app instead' : 'Use a recovery code instead' }}
        </button>
    </p>
</div>
