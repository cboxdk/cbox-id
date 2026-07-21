<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Operator self-service two-factor. Mirrors the end-user settings TOTP flow, but
 * keyed on the operator identity (a separate plane from tenant subjects). Auth is
 * re-checked in boot() on every request, like the other operator components.
 */
new #[Layout('components.layouts.operator', ['title' => 'Security'])] class extends Component
{
    public ?string $secret = null;

    public ?string $provisioningUri = null;

    #[Validate('required|digits:6')]
    public string $code = '';

    /** @var list<string> Shown exactly once, right after generation. */
    public array $recoveryCodes = [];

    /** Disabling the second factor requires re-entering the operator password. */
    public bool $confirmingDisable = false;

    public string $disablePassword = '';

    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function enable(OperatorAuth $auth, OperatorMfa $mfa): void
    {
        $operator = $auth->current();
        if ($operator === null) {
            abort(403);
        }

        // Enrolling overwrites any existing (unconfirmed) secret. Behind the live
        // operator session, which boot() re-verifies on every request.
        $enrollment = $mfa->enrollTotp($operator->id, $operator->email);

        $this->secret = $enrollment->secret;
        $this->provisioningUri = $enrollment->provisioningUri;
        $this->reset('code');
        $this->resetErrorBag();
    }

    public function confirm(OperatorAuth $auth, OperatorMfa $mfa): void
    {
        $operator = $auth->current();
        if ($operator === null) {
            abort(403);
        }

        $this->validate();

        // Throttle the confirm step so the first code can't be brute forced.
        $key = 'operator-mfa-confirm|'.$operator->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $mfa->confirmTotp($operator->id, $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', 'That code did not match. Try again.');

            return;
        }

        RateLimiter::clear($key);

        // Issue recovery codes immediately so a lost authenticator never locks the
        // operator out. Shown once, here and now.
        $this->recoveryCodes = $mfa->generateRecoveryCodes($operator->id);

        $this->reset('secret', 'provisioningUri', 'code');
        $this->dispatch('toast', message: 'Two-factor authentication is now enabled. Save your recovery codes below.');
    }

    public function regenerateRecoveryCodes(OperatorAuth $auth, OperatorMfa $mfa): void
    {
        $operator = $auth->current();

        if ($operator === null || ! $mfa->hasConfirmedTotp($operator->id)) {
            return;
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes($operator->id);
        $this->dispatch('toast', message: 'New recovery codes generated. Your previous codes no longer work.');
    }

    public function disable(OperatorAuth $auth, OperatorMfa $mfa, PlatformOperators $operators): void
    {
        $operator = $auth->current();
        if ($operator === null) {
            abort(403);
        }

        // No operator sudo/step-up concept exists, so re-entering the operator
        // password is the guard: a hijacked-but-stale session can't silently strip
        // the second factor.
        if (! $operators->verifyPassword($operator->id, $this->disablePassword)) {
            $this->addError('disablePassword', 'That password is incorrect.');

            return;
        }

        $mfa->disable($operator->id);
        $this->resetErrorBag();
        $this->reset('confirmingDisable', 'disablePassword', 'recoveryCodes');
        $this->dispatch('toast', message: 'Two-factor authentication disabled.');
    }

    public function cancel(): void
    {
        $this->reset('secret', 'provisioningUri', 'code', 'confirmingDisable', 'disablePassword');
        $this->resetErrorBag();
    }

    public function with(OperatorAuth $auth, OperatorMfa $mfa): array
    {
        $operator = $auth->current();

        return [
            'operator' => $operator,
            'twoFactorEnabled' => $operator !== null && $mfa->hasConfirmedTotp($operator->id),
            'recoveryRemaining' => $operator !== null ? $mfa->remainingRecoveryCodes($operator->id) : 0,
            'enrolling' => $this->secret !== null,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Platform</p>
            <h1 class="cbx-page-title">Security</h1>
            <p class="cbx-page-desc">Protect your operator account with a second factor.</p>
        </div>
    </div>

    {{-- Two-factor authentication --}}
    <section class="card p-5">
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="shield" class="w-5 h-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="font-semibold">Two-factor authentication</h3>
                    @if ($twoFactorEnabled)
                        <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Enabled</span>
                    @endif
                </div>
                <p class="text-sm" style="color:var(--muted)">An authenticator app adds a second step when you sign in to the operator console.</p>
            </div>
        </div>

        @if ($twoFactorEnabled)
            <p class="text-sm" style="color:var(--muted)">
                Your operator account is protected with an authenticator app. You will be asked for a
                6-digit code at sign-in.
            </p>

            <div class="mt-4 pt-4" style="border-top:1px solid var(--border)">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h4 class="font-medium text-sm">Recovery codes</h4>
                    <span class="cbx-pill">{{ $recoveryRemaining }} left</span>
                </div>
                <p class="text-sm" style="color:var(--muted)">
                    Single-use codes to sign in if you lose your authenticator. Store them somewhere safe.
                </p>

                @if ($recoveryCodes !== [])
                    <div class="mt-3 p-3 rounded-lg grid grid-cols-2 gap-x-6 gap-y-1 mono text-sm select-all" style="background:var(--surface-2);border:1px solid var(--border)">
                        @foreach ($recoveryCodes as $rc)
                            <span>{{ $rc }}</span>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs" style="color:var(--destructive)">These are shown only once. Copy them now.</p>
                @endif

                <button wire:click="regenerateRecoveryCodes" wire:confirm="Generate new recovery codes? Your existing codes will stop working."
                        class="btn btn-ghost mt-3" wire:loading.attr="disabled">
                    <x-icon name="refresh" class="w-4 h-4" /> {{ $recoveryRemaining > 0 ? 'Regenerate codes' : 'Generate codes' }}
                </button>
            </div>

            <div class="mt-4 pt-4" style="border-top:1px solid var(--border)">
                @if (! $confirmingDisable)
                    <button wire:click="$set('confirmingDisable', true)" class="btn btn-danger" wire:loading.attr="disabled">
                        Disable 2FA
                    </button>
                @else
                    <form wire:submit="disable" class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[14rem]">
                            <label class="label" for="disablePassword">Confirm your password to disable</label>
                            <input wire:model="disablePassword" id="disablePassword" type="password" autocomplete="current-password"
                                   class="input" placeholder="••••••••••••" autofocus>
                            @error('disablePassword') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-danger" wire:loading.attr="disabled">Disable 2FA</button>
                        <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                    </form>
                @endif
            </div>
        @elseif (! $enrolling)
            <button wire:click="enable" class="btn btn-primary" wire:loading.attr="disabled">
                <x-icon name="key" class="w-4 h-4" /> Enable 2FA
            </button>
        @else
            <div class="space-y-4">
                <ol class="text-sm space-y-1" style="color:var(--muted)">
                    <li>1. Add a new account in your authenticator app.</li>
                    <li>2. Scan or paste the setup key below, then enter the 6-digit code it shows.</li>
                </ol>

                <div>
                    <span class="label">Setup key (manual entry)</span>
                    <p class="mono text-sm p-3 rounded-lg select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $secret }}</p>
                </div>

                <div>
                    <span class="label">Provisioning URI</span>
                    <p class="mono text-xs p-3 rounded-lg select-all break-all" style="background:var(--surface-2);border:1px solid var(--border);color:var(--muted)">{{ $provisioningUri }}</p>
                    <p class="mt-1 text-xs" style="color:var(--faint)">Paste this into your authenticator app if it supports otpauth:// URIs.</p>
                </div>

                <form wire:submit="confirm" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[10rem]">
                        <label class="label" for="code">6-digit code</label>
                        <input wire:model="code" id="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                               maxlength="6" class="input mono" placeholder="000000" autofocus>
                        @error('code') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Confirm</button>
                    <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                </form>
            </div>
        @endif
    </section>
</div>
