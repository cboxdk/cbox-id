<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\SocialProviders;
use App\Platform\Sudo;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * "My Account" — every user's self-service security center: password, two-factor,
 * passkeys, connected accounts and active sessions. Available to any signed-in
 * user, member or admin; organization management lives separately in Settings.
 */
new #[Layout('components.layouts.app', ['title' => 'My account'])] class extends Component
{
    // --- Password ---
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    // --- Two-factor ---
    public bool $enrolling = false;

    public ?string $secret = null;

    public ?string $provisioningUri = null;

    #[Validate('required|digits:6')]
    public string $code = '';

    /** @var list<string> Shown exactly once, right after generation. */
    public array $recoveryCodes = [];

    public function changePassword(Subjects $subjects): void
    {
        // Changing a credential is sensitive — require a fresh step-up.
        if ($this->requiresSudo()) {
            return;
        }

        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'min:12'],
        ], [
            'newPassword.min' => 'Use at least 12 characters.',
        ]);

        $me = app(CurrentUser::class);

        if (! $subjects->verifyPassword($me->id(), $this->currentPassword)) {
            $this->addError('currentPassword', 'That password is incorrect.');

            return;
        }

        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->addError('newPasswordConfirmation', 'The passwords do not match.');

            return;
        }

        $subjects->setPassword($me->id(), $this->newPassword);
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        session()->flash('status', 'Password updated.');
    }

    public function enable(Mfa $mfa): void
    {
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

    /**
     * The enrollment `otpauth://` URI rendered as an inline SVG QR code — what an
     * authenticator app or password manager scans. Themed via currentColor so it
     * follows light/dark. Returns null before enrolling.
     */
    public function qrCode(): ?string
    {
        if ($this->provisioningUri === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd(),
        ));

        return $writer->writeString($this->provisioningUri);
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

    public function signOutOtherSessions(SessionManager $sessions): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);
        $currentId = $me->session()?->id;

        Session::query()
            ->where('user_id', $me->id())
            ->whereNull('revoked_at')
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->pluck('id')
            ->each(fn (string $id) => $sessions->revoke($id));

        session()->flash('status', 'Signed out of all other sessions.');
    }

    public function unlinkProvider(string $provider, Subjects $subjects): void
    {
        if ($this->requiresSudo()) {
            return;
        }

        $me = app(CurrentUser::class);

        // Last-factor guard: never strip the only remaining way to sign in.
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

    private function requiresSudo(): bool
    {
        if (app(Sudo::class)->confirmed()) {
            return false;
        }

        session()->put('sudo.intended', route('account'));
        $this->redirectRoute('sudo', navigate: false);

        return true;
    }

    private function hasPassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        return is_string($model)
            && $model::query()->whereKey($subjectId)->value('password') !== null;
    }

    /**
     * A validated "return to your app" target when this page was reached via a
     * client-SDK profile redirect (`?return_to=`). Only a well-formed absolute https
     * URL (or http on localhost) is offered, and only as a clickable link — never an
     * automatic redirect — so it can't be an open-redirect vector.
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

        // Host allowlist — the target must be a host this environment actually
        // redirects to (a registered OAuth redirect_uri), or the console's own host.
        // Without this, `?return_to=https://evil.tld` renders a legitimate-looking
        // "Return to …" link: an open-redirect / phishing pivot on an IdP surface.
        // Client is env-scoped (BelongsToEnvironment), so this is the current realm's set.
        $allowedHosts = \Cbox\Id\OAuthServer\Models\Client::query()
            ->get(['redirect_uris'])
            ->flatMap(fn ($c): array => is_array($c->redirect_uris) ? $c->redirect_uris : [])
            ->map(fn (string $uri): ?string => parse_url($uri, PHP_URL_HOST) ?: null)
            ->filter()
            ->push(request()->getHost())
            ->unique();

        if (! $allowedHosts->contains($parts['host'])) {
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
            'hasPassword' => $me->id() !== '' && $this->hasPassword($me->id()),
            'otherSessions' => $me->id() !== ''
                ? Session::query()->where('user_id', $me->id())->whereNull('revoked_at')
                    ->when($me->session()?->id !== null, fn ($q) => $q->where('id', '!=', $me->session()?->id))
                    ->count()
                : 0,
            'twoFactorEnabled' => $me->id() !== '' && app(Mfa::class)->hasConfirmedTotp($me->id()),
            'recoveryRemaining' => $me->id() !== '' ? app(Mfa::class)->remainingRecoveryCodes($me->id()) : 0,
            'passkeys' => $me->id() !== ''
                ? WebAuthnCredential::query()->where('user_id', $me->id())->orderByDesc('created_at')->get()
                : collect(),
            'socialProviders' => SocialProviders::configured(),
            'linkedProviders' => collect(app(Subjects::class)->linkedIdentities($me->id()))->pluck('provider')->all(),
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
            <p class="cbx-page-eyebrow">You</p>
            <h1 class="cbx-page-title">My account</h1>
            <p class="cbx-page-desc">Your profile, sign-in security, and active sessions.</p>
        </div>
    </div>

    {{-- Profile --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Profile</h3>
                <p class="cbx-panel-desc">How you appear across Cbox ID.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            <div class="flex items-center gap-4">
                <span class="cbx-avatar" style="width:3rem;height:3rem;font-size:1.1rem">{{ mb_strtoupper(mb_substr($me->name(), 0, 1)) }}</span>
                <dl class="flex-1">
                    <div class="cbx-kv"><dt>Name</dt><dd class="prose">{{ $me->name() }}</dd></div>
                    <div class="cbx-kv"><dt>Email</dt><dd>{{ $me->email() ?? '—' }}</dd></div>
                    @if ($org)
                        <div class="cbx-kv"><dt>Organization</dt><dd class="prose">{{ $org->name }} <span class="badge">{{ ucfirst($me->role() ?? 'member') }}</span></dd></div>
                    @endif
                </dl>
            </div>
        </div>
    </section>

    {{-- Password --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Password</h3>
                <p class="cbx-panel-desc">{{ $hasPassword ? 'Change the password you use to sign in.' : 'Set a password to sign in without a social account or passkey.' }}</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            <form wire:submit="changePassword" class="grid gap-4 sm:max-w-md">
                @if ($hasPassword)
                    <div>
                        <label class="label" for="currentPassword">Current password</label>
                        <input wire:model="currentPassword" id="currentPassword" type="password" class="input" autocomplete="current-password">
                        @error('currentPassword') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="label" for="newPassword">New password</label>
                    <input wire:model="newPassword" id="newPassword" type="password" class="input" autocomplete="new-password" placeholder="At least 12 characters">
                    @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="newPasswordConfirmation">Confirm new password</label>
                    <input wire:model="newPasswordConfirmation" id="newPasswordConfirmation" type="password" class="input" autocomplete="new-password">
                    @error('newPasswordConfirmation') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $hasPassword ? 'Update password' : 'Set password' }}</button>
                </div>
            </form>
        </div>
    </section>

    {{-- Two-factor --}}
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
            <p class="text-sm" style="color:var(--muted)">Your account is protected with an authenticator app.</p>
            <div class="mt-4 pt-4" style="border-top:1px solid var(--border)">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h4 class="font-medium text-sm">Recovery codes</h4>
                    <span class="badge">{{ $recoveryRemaining }} left</span>
                </div>
                <p class="text-sm" style="color:var(--muted)">Single-use codes to sign in if you lose your authenticator.</p>
                @if ($recoveryCodes !== [])
                    <div class="mt-3 p-3 rounded-lg grid grid-cols-2 gap-x-6 gap-y-1 mono text-sm select-all" style="background:var(--surface-2);border:1px solid var(--border)">
                        @foreach ($recoveryCodes as $rc)<span>{{ $rc }}</span>@endforeach
                    </div>
                    <div class="mt-2 flex items-center gap-3 flex-wrap">
                        <button type="button" data-copy="{{ implode("\n", $recoveryCodes) }}" class="btn btn-ghost btn-sm" aria-label="Copy all recovery codes">
                            <x-icon name="copy" class="w-3.5 h-3.5" /> <span data-copy-label>Copy all codes</span>
                        </button>
                        <p class="text-xs" style="color:var(--destructive)">Shown only once — save them now.</p>
                    </div>
                @endif
                <button wire:click="regenerateRecoveryCodes" wire:confirm="Generate new recovery codes? Your existing codes will stop working."
                        class="btn btn-ghost mt-3" wire:loading.attr="disabled">
                    <x-icon name="refresh" class="w-4 h-4" /> {{ $recoveryRemaining > 0 ? 'Regenerate codes' : 'Generate codes' }}
                </button>
            </div>
        @elseif (! $enrolling)
            <button wire:click="enable" class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="key" class="w-4 h-4" /> Enable 2FA</button>
        @else
            <div class="space-y-4">
                <ol class="text-sm space-y-1" style="color:var(--muted)">
                    <li>1. Scan the QR code with your authenticator app or password manager — or add the key manually.</li>
                    <li>2. Enter the 6-digit code it shows.</li>
                </ol>
                <div class="flex flex-col sm:flex-row gap-4 items-start">
                    <div class="shrink-0 rounded-xl p-3" style="background:#fff;line-height:0" role="img" aria-label="Authenticator setup QR code">
                        {!! $this->qrCode() !!}
                    </div>
                    <div class="min-w-0 flex-1 w-full">
                        <span class="label">Setup key (manual entry)</span>
                        <div class="flex items-stretch gap-2">
                            <p class="mono text-sm p-3 rounded-lg select-all break-all flex-1 min-w-0" style="background:var(--surface-2);border:1px solid var(--border)">{{ $secret }}</p>
                            <button type="button" data-copy="{{ $secret }}" class="btn btn-ghost btn-sm shrink-0" aria-label="Copy setup key">
                                <x-icon name="copy" class="w-3.5 h-3.5" /> <span data-copy-label>Copy</span>
                            </button>
                        </div>
                    </div>
                </div>
                <form wire:submit="confirm" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[10rem]">
                        <label class="label" for="code">6-digit code</label>
                        <input wire:model="code" id="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="input mono" placeholder="000000" autofocus>
                        @error('code') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Confirm</button>
                    <button type="button" wire:click="cancel" class="btn btn-ghost">Cancel</button>
                </form>
            </div>
        @endif
        </div>
    </section>

    {{-- Passkeys --}}
    <section class="cbx-panel" data-passkey-only>
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Passkeys</h3>
                <p class="cbx-panel-desc">Sign in with Face ID, Touch ID, Windows Hello, or a security key — no password.</p>
            </div>
            <button type="button" data-passkey-register data-passkey-name="{{ $me->name() }}'s device"
                    data-passkey-feedback="passkey-account-msg" class="btn btn-primary shrink-0">
                <x-icon name="plus" class="w-4 h-4" /> Add passkey
            </button>
        </div>
        <div class="cbx-panel-body">
            <p id="passkey-account-msg" class="text-xs mb-2" style="min-height:1rem"></p>
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
                            <button wire:click="removePasskey('{{ $passkey->id }}')" wire:confirm="Remove this passkey?" class="btn btn-danger btn-sm">Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- Connected accounts --}}
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
                                <button wire:click="unlinkProvider('{{ $key }}')" wire:confirm="Disconnect {{ $label }}?" class="btn btn-danger btn-sm">Disconnect</button>
                            @else
                                <a href="{{ route('social.connect', $key) }}" class="btn btn-ghost btn-sm">Connect</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @error('unlink') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
        </section>
    @endif

    {{-- Sessions --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Current session</h3>
                <p class="cbx-panel-desc">The session you are signed in with right now.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            @if ($session)
                <dl>
                    <div class="cbx-kv">
                        <dt>Authentication methods</dt>
                        <dd class="prose flex flex-wrap gap-1.5">
                            @forelse ($session->amr ?? [] as $method)<span class="badge">{{ $method }}</span>@empty<span class="text-sm" style="color:var(--faint)">—</span>@endforelse
                        </dd>
                    </div>
                    <div class="cbx-kv"><dt>Signed in</dt><dd>{{ $session->created_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div class="cbx-kv"><dt>Session ID</dt><dd>{{ $session->id }}</dd></div>
                </dl>
            @else
                <p class="text-sm" style="color:var(--faint)">No active session details are available.</p>
            @endif
            <div class="mt-5 pt-4 flex flex-wrap items-center gap-3" style="border-top:1px solid var(--border)">
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button type="submit" class="btn btn-danger"><x-icon name="logout" class="w-4 h-4" /> Sign out</button>
                </form>
                @if ($otherSessions > 0)
                    <button type="button" wire:click="signOutOtherSessions" wire:confirm="Sign out of your {{ $otherSessions }} other session(s) on all devices?"
                            class="btn btn-ghost" wire:loading.attr="disabled">
                        <x-icon name="logout" class="w-4 h-4" /> Sign out other sessions ({{ $otherSessions }})
                    </button>
                @endif
            </div>
        </div>
    </section>
</div>
