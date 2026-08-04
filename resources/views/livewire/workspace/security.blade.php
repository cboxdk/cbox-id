<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use App\Platform\WorkspaceSudo;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountPasskeys;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\AccountWebAuthnCredential;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Security — self-service two-factor for the signed-in member. Each
 * member secures their OWN login (no role gate). These accounts own customer IdPs,
 * so a second factor is the single most important control on the plane.
 */
// Titled exactly as the nav entry labels it and the <h1> reads. The rail said
// "Profile", the tab said "Security" and the heading said "Profile & security" — three
// names for one page, which is what ConsoleAreasTest now refuses across every plane.
new #[Layout('components.layouts.workspace', ['title' => 'Profile & security'])] class extends Component
{
    public bool $enrolling = false;

    /**
     * The enrolment secret, its otpauth URI, and the recovery codes.
     *
     * PROTECTED, not public. Livewire serialises public properties into the wire snapshot
     * embedded in the DOM and echoes them back in the body of every subsequent
     * /livewire/update request until they are reset — and `$recoveryCodes` is only reset
     * on `disable()`, so after enrolment the full MFA-bypass set rode every round trip on
     * that page, into request-body logs and APM traces. The TOTP secret is the same
     * class of value.
     *
     * The API-keys page next door already documents this reasoning for exactly the same
     * reason; this page was the outlier. Rendered through `with()` instead, which reaches
     * the view without entering the snapshot.
     */
    /**
     * The pending enrolment lives in the SESSION, not on the component.
     *
     * It has to survive one round trip — the page renders a QR code, the person types a
     * code from their authenticator, and only then is the factor confirmed. Livewire's
     * mechanism for surviving a round trip is the wire snapshot, which is embedded in the
     * DOM and echoed back in the body of every subsequent update. A TOTP secret is not
     * something to keep there.
     *
     * The session is server-side and already the trust anchor for this whole page, so it
     * is where the pending secret belongs. Cleared the moment enrolment finishes or is
     * abandoned.
     */
    private const PENDING_SECRET = 'workspace.mfa.pending_secret';

    private const PENDING_URI = 'workspace.mfa.pending_uri';

    public string $confirmCode = '';

    /** @var list<string> */
    protected array $recoveryCodes = [];

    public string $name = '';

    /**
     * This page is a MEMBER's own profile, so it needs a member.
     *
     * A platform operator who holds no membership used to be let in and shown a form
     * bound to nobody: an empty Name, a disabled empty Email, and three buttons whose
     * handlers all began `if ($member === null) return;`. Clicking Enable took them
     * through the step-up, accepted the correct password, and dropped them on the
     * signed-out screen with no message — because {@see requiresSudo()} redirects to
     * a route that reads the member id off a session that has none.
     *
     * Their own identity, second factor and passkeys are on Platform › Security, which
     * is where they are sent. The nav no longer offers this area to them at all; this is
     * the guard for the URL, which the nav is not.
     *
     * And somebody who is neither — a suspended operator, or a session that outlived its
     * member — gets the page that says so and offers a sign-out, not a 403 on a layout
     * with no way out of it. Same reasoning as `workspace.home`, which is where they
     * arrive from.
     */
    public function mount(AccountAuth $auth, ConsoleScope $scope): void
    {
        $member = $auth->current();

        if ($member === null) {
            $this->redirectRoute(
                $scope->isPlatformOperator() ? 'platform.security' : 'workspace.no-access',
                navigate: false,
            );

            return;
        }

        $this->name = $member->name ?? '';
    }

    /**
     * The member this page acts on, or a refusal.
     *
     * Every action below used to `return;` on a null member — no toast, no error, a
     * button that simply did nothing however many times it was pressed. mount() means
     * a null here can only be a membership that lapsed mid-session, which is a real
     * event and deserves to be said out loud rather than absorbed.
     */
    private function actingMember(AccountAuth $auth): ?AccountMember
    {
        $member = $auth->current();

        if ($member === null) {
            $this->dispatch('toast', severity: 'error', message: 'Your workspace membership is no longer active — sign in again to continue.');
        }

        return $member;
    }

    public function updateProfile(AccountAuth $auth): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $member = $this->actingMember($auth);
        if ($member !== null) {
            $member->forceFill(['name' => trim($this->name)])->save();
            $this->dispatch('toast', message: 'Profile updated.');
        }
    }

    public function startEnroll(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        if ($this->requiresSudo('workspace.security')) {
            return;
        }

        $member = $this->actingMember($auth);

        if ($member === null || $mfa->hasConfirmedTotp($member->id)) {
            return;
        }

        $brand = config('cbox-id.branding.name', 'Cbox ID');
        $enrollment = $mfa->enrollTotp($member->id, $member->email, is_string($brand) ? $brand : 'Cbox ID');

        session()->put(self::PENDING_SECRET, $enrollment->secret);
        session()->put(self::PENDING_URI, $enrollment->provisioningUri);
        $this->enrolling = true;
        $this->recoveryCodes = [];
    }

    public function confirmEnroll(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        $member = $this->actingMember($auth);

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
        $this->enrolling = false;
        session()->forget([self::PENDING_SECRET, self::PENDING_URI]);
        $this->reset('confirmCode');
        $this->dispatch('toast', message: 'Two-factor authentication is on.');
    }

    public function regenerateRecoveryCodes(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        if ($this->requiresSudo('workspace.security')) {
            return;
        }

        $member = $this->actingMember($auth);

        if ($member === null || ! $mfa->hasConfirmedTotp($member->id)) {
            return;
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes($member->id);
    }

    public function disable(AccountAuth $auth, AccountMemberMfa $mfa): void
    {
        if ($this->requiresSudo('workspace.security')) {
            return;
        }

        $member = $this->actingMember($auth);

        if ($member === null) {
            return;
        }

        $mfa->disable($member->id);
        $this->recoveryCodes = [];
        $this->dispatch('toast', message: 'Two-factor authentication is off.');
    }

    public function removePasskey(string $id, AccountAuth $auth, AccountPasskeys $passkeys): void
    {
        if ($this->requiresSudo('workspace.security')) {
            return;
        }

        $member = $this->actingMember($auth);

        if ($member !== null && $passkeys->remove($id, $member->id)) {
            $this->dispatch('toast', message: 'Passkey removed.');
        }
    }

    public function qr(): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 0), new SvgImageBackEnd));

        return $writer->writeString($this->pendingUri());
    }

    private function requiresSudo(string $returnRoute): bool
    {
        if (app(WorkspaceSudo::class)->confirmed()) {
            return false;
        }

        session()->put('workspace.sudo.intended', route($returnRoute));
        $this->redirectRoute('workspace.sudo', navigate: false);

        return true;
    }

    private function pendingSecret(): string
    {
        $secret = session()->get(self::PENDING_SECRET);

        return is_string($secret) ? $secret : '';
    }

    private function pendingUri(): string
    {
        $uri = session()->get(self::PENDING_URI);

        return is_string($uri) ? $uri : '';
    }

    /** @return array<string, mixed> */
    public function with(AccountAuth $auth, AccountMemberMfa $mfa, AccountPasskeys $passkeys): array
    {
        $member = $auth->current();

        /** @var Collection<int, AccountWebAuthnCredential> $keys */
        $keys = $member === null ? collect() : $passkeys->forMember($member->id);

        return [
            'email' => $member?->email,
            'enabled' => $member !== null && $mfa->hasConfirmedTotp($member->id),
            'remainingRecoveryCodes' => $member !== null ? $mfa->remainingRecoveryCodes($member->id) : 0,
            'passkeys' => $keys,

            // Reach the view without entering the wire snapshot. Recovery codes and the
            // enrolment secret are shown once, on the render that produces them.
            'recoveryCodes' => $this->recoveryCodes,
            'enrolling' => $this->enrolling,
            'provisioningUri' => $this->pendingUri(),
            'secret' => $this->pendingSecret(),
        ];
    }
}; ?>

<div>
    {{-- Plain ampersand: x-page-header escapes {{ $title }} itself, so a pre-escaped
         entity here renders literally as "&amp;". --}}
    <x-page-header title="Profile & security" subtitle="Your account details and how you protect your sign-in." />

    {{-- Profile --}}
    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        {{-- Headings, not styled paragraphs: these are the page's section structure, and
             as <p> the only heading on the page was the h1 (WCAG 1.3.1). --}}
        <h2 class="text-sm font-medium">Profile</h2>
        <form wire:submit="updateProfile" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <label for="name" class="label">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Your name"
                       @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
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
            <h2 class="text-sm font-medium">Save your recovery codes</h2>
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
                <h2 class="font-medium">Authenticator app (TOTP)</h2>
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
                                <input wire:model="confirmCode" type="text" inputmode="numeric" autocomplete="one-time-code" class="input" placeholder="123456" aria-label="Authentication code">
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
                <h2 class="font-medium">Passkeys</h2>
                <p class="mt-1 text-sm" style="color:var(--muted)">Sign in with Touch ID, Windows Hello, or a security key — no password, phishing-resistant.</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm shrink-0"
                    data-passkey-register data-passkey-base="/workspace/passkeys" data-passkey-name="Passkey" data-passkey-feedback="pk-feedback">Add a passkey</button>
        </div>
        <p id="pk-feedback" class="mt-2 text-xs" aria-live="polite"></p>

        @if ($passkeys->isNotEmpty())
            <div class="mt-4 space-y-2">
                @foreach ($passkeys as $pk)
                    <div wire:key="passkey-{{ $pk->id }}" class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)">
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
