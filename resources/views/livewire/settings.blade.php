<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\SocialProviders;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Settings'])] class extends Component
{
    public bool $enrolling = false;

    public string $brandColor = '';

    public string $brandLogoUrl = '';

    public function mount(): void
    {
        $settings = app(CurrentUser::class)->organization()?->settings ?? [];
        $this->brandColor = is_string($settings['brand_color'] ?? null) ? $settings['brand_color'] : '';
        $this->brandLogoUrl = is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '';
    }

    public function saveBranding(Organizations $organizations): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);

        $this->validate([
            'brandColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brandLogoUrl' => ['nullable', 'url', 'max:500'],
        ], [
            'brandColor.regex' => 'Use a 6-digit hex colour, e.g. #4f46e5.',
        ]);

        $orgId = app(CurrentUser::class)->organizationId();

        if ($orgId !== null) {
            $organizations->updateSettings($orgId, [
                'brand_color' => $this->brandColor ?: null,
                'brand_logo_url' => $this->brandLogoUrl ?: null,
            ]);
            session()->flash('status', 'Branding saved.');
        }
    }

    public ?string $secret = null;

    public ?string $provisioningUri = null;

    #[Validate('required|digits:6')]
    public string $code = '';

    /** @var list<string> Shown exactly once, right after generation. */
    public array $recoveryCodes = [];

    public function enable(Mfa $mfa): void
    {
        // Enrolling TOTP overwrites any existing secret (updateOrCreate). Behind a
        // fresh step-up so a hijacked-but-stale session can't silently replace the
        // victim's second factor with the attacker's.
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);
        $enrollment = $mfa->enrollTotp($me->id(), $me->email() ?? $me->name(), 'Cbox ID');

        $this->secret = $enrollment->secret;
        $this->provisioningUri = $enrollment->provisioningUri;
        $this->enrolling = true;
        $this->reset('code');
        $this->resetErrorBag();
    }

    public function confirm(Mfa $mfa): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        $this->validate();

        if (! $mfa->confirmTotp(app(CurrentUser::class)->id(), $this->code)) {
            $this->addError('code', 'That code did not match. Try again.');

            return;
        }

        // Issue recovery codes immediately so the user is never locked out if they
        // lose the authenticator. Shown once, here and now.
        $this->recoveryCodes = $mfa->generateRecoveryCodes(app(CurrentUser::class)->id());

        $this->reset('enrolling', 'secret', 'provisioningUri', 'code');
        session()->flash('status', 'Two-factor authentication is now enabled. Save your recovery codes below.');
    }

    public function regenerateRecoveryCodes(Mfa $mfa): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);

        if (! $mfa->hasConfirmedTotp($me->id())) {
            return;
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes($me->id());
        session()->flash('status', 'New recovery codes generated. Your previous codes no longer work.');
    }

    public function cancel(): void
    {
        $this->reset('enrolling', 'secret', 'provisioningUri', 'code');
        $this->resetErrorBag();
    }

    public function removePasskey(string $id): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        WebAuthnCredential::query()
            ->where('user_id', app(CurrentUser::class)->id())
            ->where('id', $id)
            ->delete();

        session()->flash('status', 'Passkey removed.');
    }

    /**
     * Sensitive actions require a fresh step-up ("sudo") confirmation. If it's
     * stale, remember where to return and send the user to re-authenticate.
     */
    public function signOutOtherSessions(SessionManager $sessions): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);
        $currentId = $me->session()?->id;

        // Revoke every active session for this user except the one they're on —
        // the "sign out everywhere else" control auditors and users expect.
        Session::query()
            ->where('user_id', $me->id())
            ->whereNull('revoked_at')
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->pluck('id')
            ->each(fn (string $id) => $sessions->revoke($id));

        session()->flash('status', 'Signed out of all other sessions.');
    }

    private function requiresSudo(): bool
    {
        if (app(Sudo::class)->confirmed()) {
            return false;
        }

        session()->put('sudo.intended', route('settings'));
        $this->redirectRoute('sudo', navigate: false);

        return true;
    }

    public function unlinkProvider(string $provider, Subjects $subjects): void
    {
        // Disconnecting a login method is sensitive — require a fresh step-up.
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);

        // Last-factor guard: never let a user strip their only remaining way to
        // sign in (would lock them out). A method survives if — after this unlink —
        // they still have another linked identity, a passkey, or a password.
        $remainingProviders = collect($subjects->linkedIdentities($me->id()))
            ->reject(fn (array $identity): bool => $identity['provider'] === 'social:'.$provider)
            ->isNotEmpty();
        $hasPasskey = WebAuthnCredential::query()->where('user_id', $me->id())->exists();

        if (! $remainingProviders && ! $hasPasskey && ! $this->hasPassword($me->id())) {
            $this->addError('unlink', 'This is your only sign-in method — add a password or passkey before disconnecting it.');

            return;
        }

        $subjects->unlink($me->id(), 'social:'.$provider);
        session()->flash('status', ucfirst($provider).' disconnected.');
    }

    private function hasPassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        return is_string($model)
            && $model::query()->whereKey($subjectId)->value('password') !== null;
    }

    /**
     * A validated "return to your app" target, when this page was reached via a
     * client SDK profile redirect (`?return_to=`). Only a well-formed absolute https
     * URL (or http on localhost for dev) is offered, and only as a clickable link
     * showing its host — never an automatic redirect — so it can't be an open-redirect
     * or phishing vector.
     *
     * @return array{url: string, host: string}|null
     */
    private function validReturnTo(): ?array
    {
        $url = request()->query('return_to');

        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $isLocal = in_array($parts['host'], ['localhost', '127.0.0.1'], true);

        if ($parts['scheme'] !== 'https' && ! ($isLocal && $parts['scheme'] === 'http')) {
            return null;
        }

        return ['url' => $url, 'host' => $parts['host']];
    }

    public function with(): array
    {
        $me = app(CurrentUser::class);

        return [
            'returnTo' => $this->validReturnTo(),
            'me' => $me,
            'org' => $me->organization(),
            'session' => $me->session(),
            'otherSessions' => $me->id() !== ''
                ? Session::query()
                    ->where('user_id', $me->id())->whereNull('revoked_at')
                    ->when($me->session()?->id !== null, fn ($q) => $q->where('id', '!=', $me->session()?->id))
                    ->count()
                : 0,
            'twoFactorEnabled' => $me->id() !== '' && app(Mfa::class)->hasConfirmedTotp($me->id()),
            'recoveryRemaining' => $me->id() !== '' ? app(Mfa::class)->remainingRecoveryCodes($me->id()) : 0,
            'passkeys' => $me->id() !== ''
                ? WebAuthnCredential::query()->where('user_id', $me->id())->orderByDesc('created_at')->get()
                : collect(),
            'socialProviders' => SocialProviders::configured(),
            'linkedProviders' => collect(app(Subjects::class)->linkedIdentities($me->id()))
                ->pluck('provider')->all(),
        ];
    }
}; ?>

<div class="space-y-6">
    @if ($returnTo)
        <a href="{{ $returnTo['url'] }}" class="inline-flex items-center gap-2 text-sm font-medium" style="color:var(--accent)">
            <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(90deg)" /> Return to {{ $returnTo['host'] }}
        </a>
    @endif

    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Account</p>
            <h1 class="cbx-page-title">Settings</h1>
            <p class="cbx-page-desc">Your organization, security, and current session.</p>
        </div>
    </div>

    {{-- A) Organization --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Organization</h3>
                <p class="cbx-panel-desc">The workspace you are currently signed in to.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            @if ($org)
                <dl>
                    <div class="cbx-kv"><dt>Name</dt><dd class="prose">{{ $org->name }}</dd></div>
                    <div class="cbx-kv"><dt>Slug</dt><dd>{{ $org->slug }}</dd></div>
                    <div class="cbx-kv"><dt>Type</dt><dd class="prose"><span class="badge">{{ ucfirst($org->type->value) }}</span></dd></div>
                    <div class="cbx-kv"><dt>Organization ID</dt><dd>{{ $org->id }}</dd></div>
                </dl>
                <p class="mt-4 text-xs" style="color:var(--faint)">Renaming and other organization settings are coming soon.</p>
            @else
                <p class="text-sm" style="color:var(--faint)">No organization is associated with this session.</p>
            @endif
        </div>
    </section>

    {{-- A2) Login branding --}}
    @if ($me->isAdmin() && $org)
        <section class="cbx-panel">
            <div class="cbx-panel-header">
                <div>
                    <h3 class="cbx-panel-title">Login branding</h3>
                    <p class="cbx-panel-desc">Theme your organization's sign-in page. Your team signs in at
                        <a href="{{ route('login.branded', $org->slug) }}" class="mono underline" style="color:var(--accent)">/o/{{ $org->slug }}/login</a>.</p>
                </div>
            </div>
            <div class="cbx-panel-body">
            <form wire:submit="saveBranding" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="brandColor">Primary colour</label>
                    <div class="flex items-center gap-2">
                        <input wire:model="brandColor" id="brandColor" type="text" class="input mono" placeholder="#4f46e5" style="flex:1">
                        <span class="rounded-md shrink-0" style="width:2.4rem;height:2.4rem;border:1px solid var(--border);background:{{ preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor) ? $brandColor : 'var(--surface-2)' }}"></span>
                    </div>
                    @error('brandColor') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="brandLogoUrl">Logo URL (https)</label>
                    <input wire:model="brandLogoUrl" id="brandLogoUrl" type="url" class="input" placeholder="https://acme.com/logo.svg">
                    @error('brandLogoUrl') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save branding</button>
                </div>
            </form>
            </div>
        </section>
    @endif

    {{-- B) Two-factor authentication --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Two-factor authentication</h3>
                <p class="cbx-panel-desc">An authenticator app adds a second step when you sign in.</p>
            </div>
            @if ($twoFactorEnabled)
                <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Enabled</span>
            @endif
        </div>
        <div class="cbx-panel-body">
        @if ($twoFactorEnabled)
            <p class="text-sm" style="color:var(--muted)">
                Your account is protected with an authenticator app. You will be asked for a
                6-digit code at sign-in.
            </p>

            <div class="mt-4 pt-4" style="border-top:1px solid var(--border)">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h4 class="font-medium text-sm">Recovery codes</h4>
                    <span class="badge">{{ $recoveryRemaining }} left</span>
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
        </div>
    </section>

    {{-- B2) Passkeys --}}
    <section class="cbx-panel" data-passkey-only>
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Passkeys</h3>
                <p class="cbx-panel-desc">Sign in with Face ID, Touch ID, Windows Hello, or a security key — no password.</p>
            </div>
            <button type="button" data-passkey-register data-passkey-name="{{ $me->name() }}'s device"
                    data-passkey-feedback="passkey-settings-msg" class="btn btn-primary shrink-0">
                <x-icon name="plus" class="w-4 h-4" /> Add passkey
            </button>
        </div>
        <div class="cbx-panel-body">
            <p id="passkey-settings-msg" class="text-xs mb-2" style="min-height:1rem"></p>

            @if ($passkeys->isEmpty())
                <p class="text-sm" style="color:var(--faint)">No passkeys registered yet.</p>
            @else
                <ul class="divide-y" style="border-color:var(--border)">
                    @foreach ($passkeys as $passkey)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <x-icon name="shield" class="w-4 h-4 shrink-0" style="color:var(--success)" />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium truncate">{{ $passkey->name ?? 'Passkey' }}</p>
                                    <p class="text-xs" style="color:var(--faint)">Added {{ $passkey->created_at?->format('M j, Y') }} · sign-count {{ $passkey->sign_count }}</p>
                                </div>
                            </div>
                            <button wire:click="removePasskey('{{ $passkey->id }}')" wire:confirm="Remove this passkey?"
                                    class="btn btn-danger btn-sm">Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- B3) Connected accounts (explicit linking) --}}
    @if (! empty($socialProviders))
        <section class="cbx-panel">
            <div class="cbx-panel-header">
                <div>
                    <h3 class="cbx-panel-title">Connected accounts</h3>
                    <p class="cbx-panel-desc">Link a social account to sign in with it. Linking is deliberate — we never merge accounts by email automatically.</p>
                </div>
            </div>
            <div class="cbx-panel-body">
                <ul class="divide-y" style="border-color:var(--border)">
                    @foreach ($socialProviders as $key => $label)
                        @php $isLinked = in_array('social:'.$key, $linkedProviders, true); @endphp
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="font-medium">{{ $label }}</span>
                                @if ($isLinked) <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Connected</span> @endif
                            </div>
                            @if ($isLinked)
                                <button wire:click="unlinkProvider('{{ $key }}')" wire:confirm="Disconnect {{ $label }}?"
                                        class="btn btn-danger btn-sm">Disconnect</button>
                            @else
                                <a href="{{ route('social.connect', $key) }}" class="btn btn-ghost btn-sm">Connect</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- C) Current session --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Current session</h3>
                <p class="cbx-panel-desc">Details of the session you are signed in with right now.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            @if ($session)
                <dl>
                    <div class="cbx-kv">
                        <dt>Authentication methods</dt>
                        <dd class="prose flex flex-wrap gap-1.5">
                            @forelse ($session->amr ?? [] as $method)
                                <span class="badge">{{ $method }}</span>
                            @empty
                                <span class="text-sm" style="color:var(--faint)">—</span>
                            @endforelse
                        </dd>
                    </div>
                    <div class="cbx-kv"><dt>Signed in</dt><dd>{{ $session->created_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div class="cbx-kv"><dt>Expires</dt><dd>{{ $session->expires_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div class="cbx-kv"><dt>Session ID</dt><dd>{{ $session->id }}</dd></div>
                </dl>
            @else
                <p class="text-sm" style="color:var(--faint)">No active session details are available.</p>
            @endif

            <div class="mt-5 pt-4 flex flex-wrap items-center gap-3" style="border-top:1px solid var(--border)">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-danger">
                        <x-icon name="logout" class="w-4 h-4" /> Sign out
                    </button>
                </form>

                @if ($otherSessions > 0)
                    <button type="button" wire:click="signOutOtherSessions"
                            wire:confirm="Sign out of your {{ $otherSessions }} other session(s) on all devices?"
                            class="btn btn-ghost" wire:loading.attr="disabled">
                        <x-icon name="logout" class="w-4 h-4" /> Sign out other sessions ({{ $otherSessions }})
                    </button>
                @endif
            </div>
        </div>
    </section>
</div>
