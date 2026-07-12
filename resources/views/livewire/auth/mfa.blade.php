<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Two-factor verification'])] class extends Component
{
    #[Validate('required|digits:6')]
    public string $code = '';

    public function mount(PlatformAuth $auth): void
    {
        // No pending password step -> nothing to verify.
        if ($auth->pendingMfaSubject(request()) === null) {
            $this->redirectRoute('login', navigate: false);
        }
    }

    public function verify(PlatformAuth $auth): void
    {
        $this->validate();

        if (! $auth->completeMfa(request(), $this->code)) {
            $this->addError('code', 'That code is incorrect or has expired.');

            return;
        }

        $this->redirectRoute('dashboard', navigate: false);
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Two-factor verification</h1>
    <p class="mt-1.5 text-sm" style="color:var(--muted)">Enter the 6-digit code from your authenticator app.</p>

    <form wire:submit="verify" class="mt-6 space-y-4">
        <div>
            <label class="label" for="code">Authentication code</label>
            <input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   class="input mono" style="letter-spacing:0.5em;font-size:1.1rem;text-align:center" placeholder="000000" autofocus>
            @error('code') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="verify">Verify</span>
            <span wire:loading wire:target="verify">Verifying…</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline underline-offset-2" style="color:var(--muted)">Cancel and sign out</button>
    </form>
</div>
