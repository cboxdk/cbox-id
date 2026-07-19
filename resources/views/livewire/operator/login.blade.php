<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Operator sign-in — and, on a fresh install with no operators yet, the one-time
 * bootstrap that creates the first one. Once any operator exists, the bootstrap
 * path is closed (guarded server-side), so it can never be used to add an
 * operator without authenticating.
 */
new #[Layout('components.layouts.auth', ['title' => 'Operator sign in'])] class extends Component
{
    public string $email = '';

    public string $password = '';

    public string $name = '';

    public bool $bootstrap = false;

    public function mount(OperatorAuth $auth, PlatformOperators $operators)
    {
        if ($auth->check()) {
            return redirect()->route('operator.environments');
        }

        // First run: no operator provisioned yet — offer to create the first.
        $this->bootstrap = ! $operators->exists();
    }

    public function login(OperatorAuth $auth): void
    {
        $this->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string',
        ]);

        // Throttle + lock out brute force, keyed on email + IP — the operator
        // console is the highest-value surface, so it gets at least the same
        // discipline as the user login (5 attempts / minute).
        $key = 'operator-login|'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $result = $auth->attempt(request(), $this->email, $this->password);

        if ($result === AttemptOutcome::Invalid) {
            RateLimiter::hit($key, 60);
            // Neutral message — never reveal whether the email is a real operator.
            $this->addError('email', 'Those credentials do not match an operator.');

            return;
        }

        RateLimiter::clear($key);

        // A confirmed TOTP factor holds the operator at the MFA challenge — no full
        // session exists yet. Otherwise the session is already established.
        $this->redirect(
            route($result === AttemptOutcome::Mfa ? 'operator.login.mfa' : 'operator.environments'),
            navigate: false,
        );
    }

    public function createFirst(PlatformOperators $operators, OperatorAuth $auth): void
    {
        $this->validate([
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:12',
        ]);

        // Serialize the bootstrap so two concurrent first-run POSTs can't each
        // slip past the "no operator exists" check and both claim root. The lock
        // is process-wide; the exists() check is re-evaluated inside it.
        $lock = Cache::lock('cbox:operator-bootstrap', 10);
        abort_unless($lock->get(), 429);

        try {
            // Only ever while no operator exists — the window closes the moment
            // the first one is created.
            abort_unless(! $operators->exists(), 403);

            $operators->create($this->email, $this->password, $this->name);
        } finally {
            $lock->release();
        }

        $auth->attempt(request(), $this->email, $this->password);

        $this->redirect(route('operator.environments'), navigate: false);
    }
}; ?>

<div>
    @if ($bootstrap)
        <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Create the first operator</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">
            Platform operators administer environments. This one-time step provisions the first.
        </p>

        <form wire:submit="createFirst" class="mt-7 space-y-4">
            <div>
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input input-lg" placeholder="Root Operator" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">Email</label>
                <input wire:model="email" id="email" type="email" class="input input-lg" placeholder="operator@yourco.example">
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input wire:model="password" id="password" type="password" autocomplete="new-password" class="input input-lg" placeholder="At least 12 characters">
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">Create operator &amp; sign in</button>
        </form>
    @else
        <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Operator sign in</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">Administer environments and platform operators.</p>

        <form wire:submit="login" class="mt-7 space-y-4">
            <div>
                <label class="label" for="email">Email</label>
                <input wire:model="email" id="email" name="email" type="email" autocomplete="username" class="input input-lg" placeholder="operator@yourco.example" autofocus>
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input wire:model="password" id="password" name="password" type="password" autocomplete="current-password" class="input input-lg" placeholder="••••••••••••">
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">Sign in</button>
        </form>
    @endif

    <p class="mt-6 text-xs" style="color:var(--faint)">
        Looking for the organization console? <a href="{{ route('login') }}" class="underline underline-offset-2" style="color:var(--accent)">Sign in there</a>.
    </p>
</div>
