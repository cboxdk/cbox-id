<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Connect a device'])] class extends Component
{
    #[Validate('required|string')]
    public string $userCode = '';

    // Everything below is set by lookup() and #[Locked] so the browser cannot mutate
    // the app identity, scopes, or the exact code we resolved and showed the user
    // between requests — otherwise a swapped user_code could approve a DIFFERENT
    // request than the one consented to (the same class of hole the OAuth consent
    // screen locks its redirect_uri against).
    #[Locked]
    public bool $verified = false;

    #[Locked]
    public string $confirmedCode = '';

    #[Locked]
    public string $clientName = '';

    /** @var list<string> */
    #[Locked]
    public array $scopes = [];

    public ?string $outcome = null; // 'approved' | 'denied'

    public ?string $error = null;

    public function mount(?string $user_code = null): void
    {
        // The device's verification_uri_complete links here with the code prefilled.
        $code = $user_code ?? request()->query('user_code');

        if (is_string($code)) {
            $this->userCode = strtoupper(trim($code));
        }
    }

    /**
     * Step 1 — resolve the code to the app + scopes it authorizes, so the user sees
     * exactly what they are approving before they approve it. Rate-limited per user
     * so a signed-in session cannot be used to brute-force short user_codes.
     */
    public function lookup(DeviceAuthorization $devices, ClientRegistry $clients, CurrentUser $me): void
    {
        $this->validate();
        $this->error = null;

        $key = 'device-lookup|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->error = 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';

            return;
        }

        $code = trim($this->userCode);
        $pending = $devices->pending($code);
        $client = $pending !== null ? $clients->byClientId($pending->clientId) : null;

        if ($pending === null || ! $client instanceof Client) {
            RateLimiter::hit($key, 60);
            $this->error = 'That code is invalid or has expired. Check the code on your device and try again.';

            return;
        }

        RateLimiter::clear($key);

        $this->confirmedCode = $code;
        $this->clientName = $client->name;
        $this->scopes = $pending->scopes;
        $this->verified = true;
    }

    /**
     * Step 2a — approve, binding the current user + acting organization to the request.
     */
    public function approve(DeviceAuthorization $devices, CurrentUser $me): void
    {
        if (! $this->verified) {
            return;
        }

        // A suspended organization cannot connect devices or mint tokens.
        if ($me->organization()?->status === OrganizationStatus::Suspended) {
            $this->error = 'This organization has been suspended and cannot connect devices.';

            return;
        }

        $ok = $devices->approve($this->confirmedCode, $me->id(), $me->organizationId());

        // The code may have expired between lookup and approval — send them back.
        if (! $ok) {
            $this->reset('verified', 'confirmedCode', 'clientName', 'scopes');
            $this->error = 'That code is invalid or has expired. Check the code on your device and try again.';

            return;
        }

        $this->outcome = 'approved';
    }

    /**
     * Step 2b — deny, so the requesting device stops polling with access_denied.
     */
    public function deny(DeviceAuthorization $devices): void
    {
        if (! $this->verified) {
            return;
        }

        $devices->deny($this->confirmedCode);
        $this->outcome = 'denied';
    }

    public function with(): array
    {
        $labels = [
            'openid' => 'Verify your identity',
            'profile' => 'Your name',
            'email' => 'Your email address',
            'offline_access' => 'Stay signed in',
        ];

        return [
            'me' => app(CurrentUser::class),
            'scopeRows' => array_map(
                fn (string $scope): array => ['scope' => $scope, 'label' => $labels[$scope] ?? $scope],
                $this->scopes,
            ),
        ];
    }
}; ?>

<div class="max-w-md">
    <div class="cbx-page-header mb-8">
        <div>
            <h1 class="cbx-page-title">Connect a device</h1>
            <p class="cbx-page-desc">Enter the code shown on your device to link it to your account.</p>
        </div>
    </div>

    @if ($outcome === 'approved')
        <div role="status" class="card p-5 flex items-start gap-3" style="border-color:color-mix(in srgb,var(--success) 30%,transparent)">
            <x-icon name="check" class="w-5 h-5 mt-0.5" style="color:var(--success)" />
            <div>
                <p class="font-medium">Device connected</p>
                <p class="text-sm" style="color:var(--muted)">You can return to your device — it's now signed in.</p>
            </div>
        </div>
    @elseif ($outcome === 'denied')
        <div role="status" class="card p-5 text-sm" style="color:var(--muted)">
            Request denied. The device was not connected.
        </div>
    @elseif ($verified)
        {{-- Step 2: consent — show WHAT is being authorized before approving. --}}
        <div class="card p-5">
            <div class="flex items-center gap-3">
                <span class="grid place-items-center rounded-full" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                    <x-icon name="shield" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-medium truncate">{{ $clientName }}</p>
                    <p class="text-xs" style="color:var(--faint)">wants to connect to your account</p>
                </div>
            </div>

            <div class="mt-5 flex items-center gap-3 rounded-lg px-3 py-2.5" style="background:var(--accent-soft)">
                <span class="grid place-items-center rounded-full text-sm font-semibold" style="width:2rem;height:2rem;background:var(--surface);color:var(--accent)">
                    {{ strtoupper(substr($me->name(), 0, 1)) }}
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">{{ $me->name() }}</p>
                    <p class="text-xs truncate" style="color:var(--muted)">{{ $me->email() }}</p>
                </div>
            </div>

            @if (count($scopeRows) > 0)
                <p class="cbx-page-eyebrow mt-6">This will allow {{ $clientName }} to</p>
                <ul class="mt-2.5 space-y-2">
                    @foreach ($scopeRows as $row)
                        <li class="flex items-center gap-2.5 text-sm">
                            <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success)" />
                            <span>{{ $row['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @error('userCode')
                <p class="field-error mt-4" role="alert">{{ $message }}</p>
            @enderror

            <div class="mt-7 flex gap-2.5">
                <button type="button" wire:click="deny" class="btn btn-ghost btn-lg flex-1" wire:loading.attr="disabled">Deny</button>
                <button type="button" wire:click="approve" class="btn btn-primary btn-lg flex-1" wire:loading.attr="disabled">Approve</button>
            </div>

            <p class="mt-5 text-xs" style="color:var(--faint)">
                Only approve if you just started signing in on a device you own. If you don't recognize this request, deny it.
            </p>
        </div>
    @else
        {{-- Step 1: enter the code shown on the device. --}}
        <form wire:submit="lookup" class="card p-5 space-y-4">
            @if ($error)
                <div role="alert" class="rounded-lg px-3.5 py-2.5 text-sm" style="background:var(--danger-soft);color:var(--danger)">
                    {{ $error }}
                </div>
            @endif

            <div>
                <label class="label" for="userCode">Device code</label>
                <input wire:model="userCode" id="userCode" name="userCode" type="text" autocomplete="one-time-code"
                       autocapitalize="characters" spellcheck="false"
                       class="input input-lg mono text-center tracking-[0.3em]" placeholder="XXXX-XXXX"
                       @error('userCode') aria-invalid="true" aria-describedby="userCode-error" @enderror>
                @error('userCode') <p class="field-error" id="userCode-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">Continue</button>
            <p class="text-xs" style="color:var(--faint)">You'll see which app is asking before anything is connected.</p>
        </form>
    @endif
</div>
