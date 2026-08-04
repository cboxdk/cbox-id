<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.workspace', ['title' => 'Confirm it\'s you'])] class extends Component
{
    #[Validate('required|string')]
    public string $password = '';

    public function confirm(AccountAuth $auth, AccountMembers $members, WorkspaceSudo $sudo): void
    {
        $this->validate();

        $memberId = $auth->id();

        if ($memberId === null) {
            // Reached by somebody with a live session but no MEMBERSHIP — a platform
            // operator, before the pages that send them here were gated. It used to
            // redirect to the sign-in screen with nothing said, which reads as "your
            // correct password was wrong" to a person whose session is perfectly valid.
            // Say what actually happened, and send them somewhere that is theirs.
            session()->forget('workspace.sudo.intended');

            $this->addError('password', 'This step-up is for a workspace member, and this session holds no membership. Your own security settings are on Platform › Security.');

            return;
        }

        // Throttle re-auth just like login — a live session shouldn't grant
        // unlimited password guesses.
        $key = 'workspace-sudo|'.$memberId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('password', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $members->verifyPassword($memberId, $this->password)) {
            RateLimiter::hit($key, 60);
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = session()->pull('workspace.sudo.intended');
        $this->redirect(is_string($intended) ? $intended : route('workspace.security'), navigate: false);
    }

    /**
     * Where Cancel goes — the page that asked for the step-up, PEEKED not pulled: the
     * person may still go on to confirm, and confirm() is what spends the intent.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $intended = session()->get('workspace.sudo.intended');

        return ['cancelHref' => is_string($intended) ? $intended : route('workspace.home')];
    }
}; ?>

<div class="max-w-md">
    <div class="mb-6">
        <span class="grid place-items-center rounded-full mb-4" style="width:2.5rem;height:2.5rem;background:var(--accent-soft);color:var(--accent-strong)">
            <x-icon name="shield" class="w-5 h-5" />
        </span>
        <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Confirm it's you</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">
            This is a protected action. Re-enter your password to continue.
        </p>
    </div>

    <div class="card p-5">
        <form wire:submit="confirm" class="space-y-4">
            <div>
                <label for="sudo-password" class="block text-sm font-medium mb-1.5">Password</label>
                <input
                    id="sudo-password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    autofocus
                    class="input w-full"
                    @error('password') aria-invalid="true" aria-describedby="sudo-password-error" @enderror
                />
                @error('password')
                    <p id="sudo-password-error" class="mt-1.5 text-sm" role="alert" style="color:var(--danger)">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="confirm">
                    <span wire:loading.remove wire:target="confirm">Confirm</span>
                    <span wire:loading wire:target="confirm">Confirming…</span>
                </button>
                {{-- A step-up is an interruption, so it needs a way out that is not the
                     browser's Back button: this page has no rail entry of its own, so
                     Back is the only other exit and it re-posts the form on some
                     browsers. Points at whatever asked for the step-up. --}}
                <a href="{{ $cancelHref }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
