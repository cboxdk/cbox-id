<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountPasskeys;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Security — self-service two-factor for the signed-in member. Each
 * member secures their OWN login (no role gate). These accounts own customer IdPs,
 * so a second factor is the single most important control on the plane.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Security'])] class extends Component
{
    public bool $enrolling = false;

    public string $secret = '';

    public string $provisioningUri = '';

    public string $confirmCode = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public string $name = '';

    public function mount(AccountAuth $auth): void
    {
        $this->name = $auth->current()?->name ?? '';
    }

    public function updateProfile(AccountAuth $auth): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $member = $auth->current();
        if ($member !== null) {
            $member->forceFill(['name' => trim($this->name)])->save();
            session()->flash('status', 'Profile updated.');
        }
    }

    public function startEnroll(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $member = $auth->current();

        if ($member === null || $mfa->hasConfirmedTotp($member->id)) {
            return;
        }

        $brand = config('cbox-id.branding.name', 'Cbox ID');
        $enrollment = $mfa->enrollTotp($member->id, $member->email, is_string($brand) ? $brand : 'Cbox ID');

        $this->secret = $enrollment->secret;
        $this->provisioningUri = $enrollment->provisioningUri;
        $this->enrolling = true;
        $this->recoveryCodes = [];
    }

    public function confirmEnroll(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $member = $auth->current();

        if ($member === null) {
            return;
        }

        $this->validate(['confirmCode' => ['required', 'string']]);

        if (! $mfa->confirmTotp($member->id, $this->confirmCode)) {
            $this->addError('confirmCode', 'That code is not valid — check your authenticator and try again.');

            return;
        }

        // Fresh recovery codes, shown exactly once.
        $this->recoveryCodes = $mfa->generateRecoveryCodes($member->id);
        $this->reset('enrolling', 'secret', 'provisioningUri', 'confirmCode');
        session()->flash('status', 'Two-factor authentication is on.');
    }

    public function regenerateRecoveryCodes(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $member = $auth->current();

        if ($member === null || ! $mfa->hasConfirmedTotp($member->id)) {
            return;
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes($member->id);
    }

    public function disable(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $member = $auth->current();

        if ($member === null) {
            return;
        }

        $mfa->disable($member->id);
        $this->reset('recoveryCodes');
        session()->flash('status', 'Two-factor authentication is off.');
    }

    public function removePasskey(string $id, AccountAuth $auth, AccountPasskeys $passkeys): void
    {
        $member = $auth->current();

        if ($member !== null && $passkeys->remove($id, $member->id)) {
            session()->flash('status', 'Passkey removed.');
        }
    }

    public function qr(): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 0), new SvgImageBackEnd));

        return $writer->writeString($this->provisioningUri);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMemberMfa $mfa, AccountPasskeys $passkeys): array
    {
        $member = $auth->current();

        /** @var Collection<int, \Cbox\Id\Platform\Models\AccountWebAuthnCredential> $keys */
        $keys = $member === null ? collect() : $passkeys->forMember($member->id);

        return [
            'email' => $member?->email,
            'enabled' => $member !== null && $mfa->hasConfirmedTotp($member->id),
            'remainingRecoveryCodes' => $member !== null ? $mfa->remainingRecoveryCodes($member->id) : 0,
            'passkeys' => $keys,
        ];
    }
}; ?>

<div>
    <div>
        <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Profile &amp; security</h1>
        <p class="mt-1 text-sm" style="color:var(--muted)">Your account details and how you protect your sign-in.</p>
    </div>

    {{-- Profile --}}
    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Profile</p>
        <form wire:submit="updateProfile" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <label for="name" class="label">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Your name">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label">Email</label>
                <input type="email" class="input" value="{{ $email }}" disabled style="opacity:.65">
            </div>
            <button type="submit" class="btn btn-primary shrink-0 self-end" wire:loading.attr="disabled" wire:target="updateProfile">Save</button>
        </form>
    </div>

    {{-- Freshly-generated recovery codes — shown exactly once. --}}
    @if ($recoveryCodes !== [])
        <div class="mt-6 rounded-xl border p-5" style="border-color:color-mix(in oklch,var(--warning) 35%,transparent);background:color-mix(in oklch,var(--warning) 8%,var(--background))">
            <p class="text-sm font-medium">Save your recovery codes</p>
            <p class="mt-1 text-sm" style="color:var(--muted)">Each works once if you lose your authenticator. Store them somewhere safe — you won't see them again.</p>
            <div class="mt-3 grid grid-cols-2 gap-2 mono text-sm">
                @foreach ($recoveryCodes as $rc)
                    <div class="rounded-lg px-3 py-2" style="background:var(--background);border:1px solid var(--border)">{{ $rc }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="font-medium">Authenticator app (TOTP)</p>
                <p class="mt-1 text-sm" style="color:var(--muted)">
                    @if ($enabled)
                        On · {{ $remainingRecoveryCodes }} recovery {{ \Illuminate\Support\Str::plural('code', $remainingRecoveryCodes) }} left
                    @else
                        Use Google Authenticator, 1Password, or any TOTP app.
                    @endif
                </p>
            </div>
            @if ($enabled)
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="regenerateRecoveryCodes">Regenerate codes</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="disable" wire:confirm="Turn off two-factor authentication?">Turn off</button>
                </div>
            @elseif (! $enrolling)
                <button type="button" class="btn btn-primary btn-sm shrink-0" wire:click="startEnroll">Enable</button>
            @endif
        </div>

        @if ($enrolling)
            <div class="mt-5 pt-5" style="border-top:1px solid var(--border)">
                <p class="text-sm" style="color:var(--muted)">Scan this with your authenticator app, then enter the 6-digit code to confirm.</p>
                <div class="mt-4 flex flex-col sm:flex-row gap-5 items-start">
                    <div class="rounded-lg p-3 shrink-0" style="background:#fff">{!! $this->qr() !!}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs" style="color:var(--faint)">Or enter this key manually:</p>
                        <code class="mt-1 block rounded-lg px-3 py-2 text-sm break-all" style="background:var(--surface-2)">{{ $secret }}</code>
                        <form wire:submit="confirmEnroll" class="mt-4 flex items-start gap-2">
                            <div>
                                <input wire:model="confirmCode" type="text" inputmode="numeric" autocomplete="one-time-code" class="input" placeholder="123456">
                                @error('confirmCode') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary">Confirm</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Passkeys — the strongest, phishing-resistant factor. --}}
    <div class="mt-4 rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="font-medium">Passkeys</p>
                <p class="mt-1 text-sm" style="color:var(--muted)">Sign in with Touch ID, Windows Hello, or a security key — no password, phishing-resistant.</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm shrink-0"
                    data-passkey-register data-passkey-base="/workspace/passkeys" data-passkey-name="Passkey" data-passkey-feedback="pk-feedback">Add a passkey</button>
        </div>
        <p id="pk-feedback" class="mt-2 text-xs" aria-live="polite"></p>

        @if ($passkeys->isNotEmpty())
            <div class="mt-4 space-y-2">
                @foreach ($passkeys as $pk)
                    <div class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)">
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ $pk->name ?? 'Passkey' }}</p>
                            <p class="text-xs" style="color:var(--faint)">Added {{ $pk->created_at?->diffForHumans() }}</p>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)"
                                wire:click="removePasskey('{{ $pk->id }}')" wire:confirm="Remove this passkey?">Remove</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
