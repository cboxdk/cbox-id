<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Rules\NotBreached;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
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

    // Honeypot: a real user never fills `website`; `renderedAt` catches bots that
    // submit implausibly fast. Both feed the risk scorer.
    public string $website = '';

    public int $renderedAt = 0;

    public function mount(): void
    {
        $this->renderedAt = now()->timestamp;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // NIST SP 800-63B favors length over composition: a 12-char minimum,
            // no forced complexity, plus a known-breach screen (HIBP k-anonymity).
            'password' => ['required', 'string', 'min:12', 'max:200', new NotBreached],
        ];
    }

    public function register(Subjects $subjects, Organizations $orgs, Memberships $memberships, PlatformAuth $auth, RiskGuard $risk): void
    {
        $this->validate();

        // Throttle to blunt account-enumeration and automated signup abuse.
        $key = 'signup|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 300);

        // Risk-score the signup (bot/abuse detection). Logged for review; blocks a
        // Reject only when enforcement is enabled.
        $assessment = $risk->assess(request(), 'register', $this->email, [
            'honeypot' => $this->website,
            'form_rendered_at' => $this->renderedAt,
        ]);

        if ($risk->shouldBlock($assessment)) {
            $this->addError('email', 'We could not process this request. Please try again later.');

            return;
        }

        if ($subjects->findByEmail($this->email) !== null) {
            $this->addError('email', 'An account with this email already exists.');

            return;
        }

        $subject = $subjects->create($this->email, $this->name, $this->password);

        $organization = $orgs->create(new NewOrganization($this->organization, $this->uniqueSlug($orgs)));
        $memberships->add($organization->id, $subject->id, 'owner');

        // Send a confirmation link; the account is usable immediately, verification
        // just confirms the address out of band.
        $verifyToken = app(EmailVerification::class)->issue($subject->id, $this->email);
        Mail::to($this->email)->send(new EmailVerificationMail(route('verification.verify', $verifyToken)));

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
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Create your organization</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Set up Cbox ID for your team in under a minute.</p>

    {{-- name + autocomplete so password managers offer to save; passwordrules/minlength
         let them generate a policy-compliant password (NIST: length over complexity). --}}
    <form wire:submit="register" class="mt-7 space-y-4" method="post" action="{{ route('signup') }}">
        {{-- Honeypot: hidden from humans, tempting to bots. Must stay empty. --}}
        <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px" tabindex="-1">
            <label for="website">Website</label>
            <input wire:model="website" id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label class="label" for="organization">Organization name</label>
            <input wire:model="organization" id="organization" name="organization" type="text" autocomplete="organization" class="input input-lg" placeholder="Acme Inc." autofocus
                   @error('organization') aria-invalid="true" aria-describedby="organization-error" @enderror>
            @error('organization') <p class="field-error" id="organization-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="label" for="name">Your name</label>
                <input wire:model="name" id="name" name="name" type="text" autocomplete="name" class="input input-lg" placeholder="Dana Reeves"
                       @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p class="field-error" id="name-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">Work email</label>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="dana@acme.com"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div x-data="{ pw: '' }">
            <label class="label" for="password">Password</label>
            <input wire:model="password" x-on:input="pw = $event.target.value"
                   id="password" name="password" type="password"
                   autocomplete="new-password" minlength="12" passwordrules="minlength: 12; allowed: ascii-printable;"
                   class="input input-lg" placeholder="At least 12 characters"
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

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="register">
            <span wire:loading.remove wire:target="register">Create organization</span>
            <span wire:loading wire:target="register" class="inline-flex items-center gap-2"><span class="spinner"></span> Creating…</span>
        </button>
    </form>

    <p class="mt-8 text-sm" style="color:var(--muted)">
        Already have an account? <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Sign in</a>
    </p>
</div>
