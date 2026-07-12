<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Settings'])] class extends Component
{
    public bool $enrolling = false;

    public ?string $secret = null;

    public ?string $provisioningUri = null;

    #[Validate('required|digits:6')]
    public string $code = '';

    public function enable(Mfa $mfa): void
    {
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
        $this->validate();

        if (! $mfa->confirmTotp(app(CurrentUser::class)->id(), $this->code)) {
            $this->addError('code', 'That code did not match. Try again.');

            return;
        }

        $this->reset('enrolling', 'secret', 'provisioningUri', 'code');
        session()->flash('status', 'Two-factor authentication is now enabled.');
    }

    public function cancel(): void
    {
        $this->reset('enrolling', 'secret', 'provisioningUri', 'code');
        $this->resetErrorBag();
    }

    public function removePasskey(string $id): void
    {
        WebAuthnCredential::query()
            ->where('user_id', app(CurrentUser::class)->id())
            ->where('id', $id)
            ->delete();

        session()->flash('status', 'Passkey removed.');
    }

    public function with(): array
    {
        $me = app(CurrentUser::class);

        return [
            'me' => $me,
            'org' => $me->organization(),
            'session' => $me->session(),
            'twoFactorEnabled' => $me->id() !== '' && app(Mfa::class)->hasConfirmedTotp($me->id()),
            'passkeys' => $me->id() !== ''
                ? WebAuthnCredential::query()->where('user_id', $me->id())->orderByDesc('created_at')->get()
                : collect(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Settings" subtitle="Your organization, security, and current session." />

    {{-- A) Organization --}}
    <section class="card p-5">
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="settings" class="w-5 h-5" />
            </span>
            <div class="min-w-0">
                <h3 class="font-semibold">Organization</h3>
                <p class="text-sm" style="color:var(--muted)">The workspace you are currently signed in to.</p>
            </div>
        </div>

        @if ($org)
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="label">Name</dt>
                    <dd class="text-sm font-medium">{{ $org->name }}</dd>
                </div>
                <div>
                    <dt class="label">Slug</dt>
                    <dd class="text-sm mono">{{ $org->slug }}</dd>
                </div>
                <div>
                    <dt class="label">Type</dt>
                    <dd><span class="badge">{{ ucfirst($org->type->value) }}</span></dd>
                </div>
                <div>
                    <dt class="label">Organization ID</dt>
                    <dd class="text-sm mono" style="color:var(--faint)">{{ $org->id }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs" style="color:var(--faint)">Renaming and other organization settings are coming soon.</p>
        @else
            <p class="text-sm" style="color:var(--faint)">No organization is associated with this session.</p>
        @endif
    </section>

    {{-- B) Two-factor authentication --}}
    <section class="card p-5">
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="shield" class="w-5 h-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="font-semibold">Two-factor authentication</h3>
                    @if ($twoFactorEnabled)
                        <span class="badge badge-success"><x-icon name="check" class="w-3.5 h-3.5" /> Enabled</span>
                    @endif
                </div>
                <p class="text-sm" style="color:var(--muted)">An authenticator app adds a second step when you sign in.</p>
            </div>
        </div>

        @if ($twoFactorEnabled)
            <p class="text-sm" style="color:var(--muted)">
                Your account is protected with an authenticator app. You will be asked for a
                6-digit code at sign-in.
            </p>
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

    {{-- B2) Passkeys --}}
    <section class="card p-5" data-passkey-only>
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="key" class="w-5 h-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-semibold">Passkeys</h3>
                <p class="text-sm" style="color:var(--muted)">Sign in with Face ID, Touch ID, Windows Hello, or a security key — no password.</p>
            </div>
            <button type="button" data-passkey-register data-passkey-name="{{ $me->name() }}'s device"
                    data-passkey-feedback="passkey-settings-msg" class="btn btn-primary shrink-0">
                <x-icon name="plus" class="w-4 h-4" /> Add passkey
            </button>
        </div>

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
                                class="btn btn-danger" style="padding:0.35rem 0.6rem;font-size:0.8rem">Remove</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- C) Current session --}}
    <section class="card p-5">
        <div class="flex items-start gap-3 mb-4">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="key" class="w-5 h-5" />
            </span>
            <div class="min-w-0">
                <h3 class="font-semibold">Current session</h3>
                <p class="text-sm" style="color:var(--muted)">Details of the session you are signed in with right now.</p>
            </div>
        </div>

        @if ($session)
            <dl class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <dt class="label">Authentication methods</dt>
                    <dd class="flex flex-wrap gap-1.5">
                        @forelse ($session->amr ?? [] as $method)
                            <span class="badge">{{ $method }}</span>
                        @empty
                            <span class="text-sm" style="color:var(--faint)">—</span>
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="label">Signed in</dt>
                    <dd class="text-sm">{{ $session->created_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="label">Expires</dt>
                    <dd class="text-sm">{{ $session->expires_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="label">Session ID</dt>
                    <dd class="text-sm mono" style="color:var(--faint)">{{ $session->id }}</dd>
                </div>
            </dl>
        @else
            <p class="text-sm" style="color:var(--faint)">No active session details are available.</p>
        @endif

        <div class="mt-5 pt-4" style="border-top:1px solid var(--border)">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-danger">
                    <x-icon name="logout" class="w-4 h-4" /> Sign out
                </button>
            </form>
        </div>
    </section>
</div>
