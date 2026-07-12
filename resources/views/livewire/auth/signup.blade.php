<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Rules\NotBreached;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Create your organization'])] class extends Component
{
    public string $organization = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // Reject passwords found in known breaches (HIBP k-anonymity).
            'password' => ['required', 'string', 'min:8', 'max:200', new NotBreached],
        ];
    }

    public function register(Subjects $subjects, Organizations $orgs, Memberships $memberships, PlatformAuth $auth): void
    {
        $this->validate();

        // Throttle to blunt account-enumeration and automated signup abuse.
        $key = 'signup|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 300);

        if ($subjects->findByEmail($this->email) !== null) {
            $this->addError('email', 'An account with this email already exists.');

            return;
        }

        $subject = $subjects->create($this->email, $this->name, $this->password);

        $organization = $orgs->create(new NewOrganization($this->organization, $this->uniqueSlug($orgs)));
        $memberships->add($organization->id, $subject->id, 'owner');

        $auth->establish(request(), $subject->id, ['pwd']);

        $this->redirectRoute('dashboard', navigate: false);
    }

    private function uniqueSlug(Organizations $orgs): string
    {
        $base = Str::slug($this->organization) ?: 'org';
        $slug = $base;
        $n = 1;

        while ($orgs->bySlug($slug) !== null) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Create your organization</h1>
    <p class="mt-1.5 text-sm" style="color:var(--muted)">Set up Cbox ID for your team in under a minute.</p>

    <form wire:submit="register" class="mt-6 space-y-4">
        <div>
            <label class="label" for="organization">Organization name</label>
            <input wire:model="organization" id="organization" type="text" class="input" placeholder="Acme Inc." autofocus>
            @error('organization') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="label" for="name">Your name</label>
                <input wire:model="name" id="name" type="text" autocomplete="name" class="input" placeholder="Dana Reeves">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">Work email</label>
                <input wire:model="email" id="email" type="email" autocomplete="username" class="input" placeholder="dana@acme.com">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" class="input" placeholder="At least 8 characters">
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Create organization</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>

    <p class="mt-8 text-sm" style="color:var(--muted)">
        Already have an account? <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Sign in</a>
    </p>
</div>
