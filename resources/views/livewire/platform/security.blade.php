<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
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
new #[Layout('components.layouts.app', ['title' => 'Security'])] class extends Component
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

    /**
     * The same proof, for the other action that mints a durable second factor.
     *
     * `regenerateRecoveryCodes()` asked for nothing but a live session — and it renders
     * ten valid single-use factors that OUTLIVE session revocation, which is the very
     * outcome `disable()`'s password check exists to prevent. A hijacked-but-stale
     * operator session reached the same end by the unguarded sibling.
     */
    public string $regeneratePassword = '';

    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    public function enable(ConsoleScope $scope, OperatorMfa $mfa): void
    {
        $operator = $scope->operator();
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

    public function confirm(ConsoleScope $scope, OperatorMfa $mfa): void
    {
        $operator = $scope->operator();
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

    public function regenerateRecoveryCodes(ConsoleScope $scope, OperatorMfa $mfa, PlatformOperators $operators): void
    {
        $operator = $scope->operator();

        if ($operator === null || ! $mfa->hasConfirmedTotp($operator->id)) {
            return;
        }

        // The password, for the same reason `disable()` asks for it: what this mints is a
        // durable second factor that survives revoking every session. Asking only for a
        // live session made this the cheaper route to the outcome that check was written
        // to close.
        if (! $this->proveOperatorPassword($operators, $operator->id, $this->regeneratePassword, 'regeneratePassword')) {
            return;
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes($operator->id);
        $this->reset('regeneratePassword');
        $this->dispatch('toast', message: 'New recovery codes generated. Your previous codes no longer work.');
    }

    /**
     * Verify the operator's password, throttled — the guard `confirm()` already had and
     * the two password checks on this page did not.
     *
     * `verifyPassword()` is bcrypt, so unbounded guessing here is slow rather than
     * instant; it is also therefore a free way to pin a CPU. The budget matches
     * `confirm()`'s exactly, because it is the same question about the same identity.
     */
    private function proveOperatorPassword(PlatformOperators $operators, string $operatorId, string $password, string $field): bool
    {
        $key = 'operator-password|'.$field.'|'.$operatorId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError($field, 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return false;
        }

        if (! $operators->verifyPassword($operatorId, $password)) {
            RateLimiter::hit($key, 60);
            $this->addError($field, 'That password is incorrect.');

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    public function disable(ConsoleScope $scope, OperatorMfa $mfa, PlatformOperators $operators): void
    {
        $operator = $scope->operator();
        if ($operator === null) {
            abort(403);
        }

        // No operator sudo/step-up concept exists, so re-entering the operator
        // password is the guard: a hijacked-but-stale session can't silently strip
        // the second factor.
        if (! $this->proveOperatorPassword($operators, $operator->id, $this->disablePassword, 'disablePassword')) {
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

    /** @return array<string, mixed> */
    public function with(ConsoleScope $scope, OperatorMfa $mfa): array
    {
        $operator = $scope->operator();

        return [
            'operator' => $operator,
            'twoFactorEnabled' => $operator !== null && $mfa->hasConfirmedTotp($operator->id),
            'recoveryRemaining' => $operator !== null ? $mfa->remainingRecoveryCodes($operator->id) : 0,
            'enrolling' => $this->secret !== null,
        ];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Security" subtitle="Your own operator identity — the second factor that protects everything on the Platform rail." />

    {{-- Two-factor authentication --}}
    <section class="card p-5">
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent-strong)">
                <x-icon name="shield" class="w-5 h-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    {{-- h2, not h3: this is the first section under the page's h1, and
                         skipping a level breaks heading navigation (WCAG 1.3.1). --}}
                    <h2 class="font-semibold">Two-factor authentication</h2>
                    @if ($twoFactorEnabled)
                        <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Enabled</span>
                    @endif
                </div>
                {{-- There is no separate "operator console" any more — operators and account
                     members sign in at the same door and see one rail, wider or narrower. --}}
                <p class="text-sm" style="color:var(--muted)">An authenticator app adds a second step whenever you sign in.</p>
            </div>
        </div>

        @if ($twoFactorEnabled)
            <p class="text-sm" style="color:var(--muted)">
                Your operator account is protected with an authenticator app. You will be asked for a
                6-digit code at sign-in.
            </p>

            <div class="mt-4 pt-4" style="border-top:1px solid var(--border)">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h3 class="font-medium text-sm">Recovery codes</h3>
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

                <div class="mt-3 max-w-sm">
                    <label class="label" for="regeneratePassword">Confirm your password to generate codes</label>
                    <input wire:model="regeneratePassword" id="regeneratePassword" type="password" autocomplete="current-password"
                           class="input mt-1" @error('regeneratePassword') aria-invalid="true" aria-describedby="regeneratePassword-error" @enderror />
                    @error('regeneratePassword')
                        <p id="regeneratePassword-error" role="alert" class="mt-1 text-sm" style="color:var(--destructive)">{{ $message }}</p>
                    @enderror
                </div>

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
                                   class="input" placeholder="••••••••••••" autofocus
                                   @error('disablePassword') aria-invalid="true" aria-describedby="disablePassword-error" @enderror>
                            @error('disablePassword') <p id="disablePassword-error" class="field-error" role="alert">{{ $message }}</p> @enderror
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
                               maxlength="6" class="input mono" placeholder="000000" autofocus
                               @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                        @error('code') <p id="code-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Confirm</button>
                    <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                </form>
            </div>
        @endif
    </section>
</div>
