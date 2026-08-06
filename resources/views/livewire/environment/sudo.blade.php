<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\StepUpReason;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Step-up re-authentication for the ENVIRONMENT control plane — the third plane's
 * mirror of `auth.sudo`.
 *
 * The password is verified against the administrator's PLATFORM-ROOT subject, resolved
 * inside {@see PlatformRoot::run()}. An environment administrator is a subject of the
 * root holding an account membership, never a subject inside the environment they are
 * administering — and `users` is environment-owned, so a lookup under the ambient tenant
 * scope would either find nothing (refusing a legitimate administrator forever) or, far
 * worse, find a same-id row belonging to the tenant.
 */
new #[Layout('components.layouts.environment', ['title' => 'Confirm it\'s you'])] class extends Component
{
    /**
     * Second layer, the same one every other environment console component carries: the
     * route's `env.admin` middleware is the primary gate and IS re-run on Livewire
     * actions, but a component that relied on it alone answered unauthenticated the one
     * time it was missing from the persistent list. boot(), not mount() — only boot()
     * runs on each action.
     *
     * Deliberately NOT behind the step-up it grants: this is the page that grants it.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    #[Validate('required|string')]
    public string $password = '';

    public function confirm(EnvironmentAdminAuth $admin, Subjects $subjects, PlatformRoot $root, EnvironmentSudo $sudo): void
    {
        $this->validate();

        $subjectId = $admin->subjectId();

        // The session went away underneath the form — send them back to the door rather
        // than confirming a step-up for nobody.
        if ($subjectId === null) {
            $this->redirectRoute('admin.login', navigate: false);

            return;
        }

        // Throttled like a sign-in: a live session must not buy unlimited password
        // guesses against the identity that administers every tenant here.
        $key = 'environment-sudo|'.$subjectId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('password', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $verified = $root->run(fn (): bool => $subjects->verifyPassword($subjectId, $this->password));

        if ($verified !== true) {
            RateLimiter::hit($key, 60);
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = session()->pull('environment.sudo.intended');
        StepUpReason::forget('environment.sudo');

        $this->redirect(is_string($intended) ? $intended : route('environment.home'), navigate: false);
    }

    /**
     * Why this screen appeared, when whatever raised it said so.
     *
     * Read per render rather than pulled into a property: a wrong password re-renders,
     * and the sentence explaining what is waiting on the other side has to still be there
     * on the second attempt. It is spent in confirm(), with the intent it belongs to.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['stepUpReason' => StepUpReason::pending('environment.sudo')];
    }
}; ?>

<div class="max-w-md">
    <div class="mb-6">
        <span class="grid place-items-center rounded-full mb-4" style="width:2.5rem;height:2.5rem;background:var(--accent-soft);color:var(--accent-strong)">
            <x-icon name="shield" class="w-5 h-5" />
        </span>
        <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Confirm it's you</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">
            {{ $stepUpReason ?? 'This is a protected action.' }} Re-enter your password to continue.
        </p>
    </div>

    <div class="card p-5">
        <form wire:submit="confirm" class="space-y-4">
            <div>
                <label class="label" for="password">Password</label>
                <input wire:model="password" id="password" type="password" autocomplete="current-password" class="input input-lg" autofocus>
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="confirm">Confirm</span>
                <span wire:loading wire:target="confirm" class="inline-flex items-center gap-2"><span class="spinner"></span> Confirming…</span>
            </button>
        </form>
    </div>
</div>
