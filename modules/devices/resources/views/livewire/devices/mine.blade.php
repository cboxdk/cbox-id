<?php

use App\Platform\CurrentUser;
use App\Platform\PlaneResolver;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Devices\Support\AuthenticatorProvisioner;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/*
 * MY devices — the personal half of the trusted-devices surface. Enrolment and one's
 * own handsets are account security, like passkeys and TOTP, so this page sits in the
 * My account area and is reachable by every signed-in user. The org-admin fleet
 * inventory (everyone's devices, delivery errors) stays on devices.index behind the
 * admin gate — that data is reconnaissance, this page is self-service.
 *
 * Styled with the host's CSS variables rather than Tailwind `dark:` utilities — the
 * console themes via a [data-theme] attribute, so a `dark:` class would render
 * permanently light and off-brand inside it.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * Self-provisioning: the first visit registers the authenticator's OAuth client,
     * so enabling the module is a config change and nothing asks anyone to run a
     * command. Any signed-in user may trigger it — the client is deterministic (fixed
     * name, scopes and derived redirect URIs) and carries no secret, so there is
     * nothing a member gains by being first.
     *
     * Failure is reported and swallowed: the page still renders, and the enrolment
     * panel explains itself instead of the whole page becoming a 500.
     */
    public function mount(): void
    {
        if (AuthenticatorClient::find() instanceof Client) {
            return;
        }

        try {
            app(AuthenticatorProvisioner::class)->ensure($this->provisioningHost());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The host baked into the claimed-HTTPS redirect URI at first provisioning.
     *
     * Multi-tenant: the request host, which EnforcePlane has already resolved to a
     * known tenant — an unrecognised Host: header 404s before reaching this page.
     * Single-tenant: the configured app.url host, NOT the request host — every host is
     * served in that shape, so trusting the header would let the first visitor poison
     * the redirect URI with a host they control.
     */
    private function provisioningHost(): string
    {
        return app(PlaneResolver::class)->isMultiTenant()
            ? request()->getHost()
            : AuthenticatorClient::hostFromAppUrl();
    }

    /**
     * The enrolment code the authenticator app scans.
     *
     * Carries the host AND a short-lived signed code bound to the signed-in subject.
     *
     * The host alone used to be the whole payload, described as safe on a screen, in a
     * screenshot, or on a wiki. Safe in the sense that it granted nothing — but it also
     * never expired and named nobody, so a photograph of it stayed a working enrolment
     * pointer indefinitely. The code fixes that: two minutes of life, and the subject
     * travels to POST /devices where it is checked against whoever actually signs in.
     *
     * It is NOT an anti-phishing control. The app fetches the verifying key from the
     * host the code names, so a code forged for a hostile host verifies there too.
     * Freshness and binding are the properties on offer; nothing more should be claimed.
     *
     * Null when provisioning failed in mount(), or when there is somehow no subject —
     * a code bound to nobody could never be spent, so rendering one would be a dead end.
     */
    private ?string $memoisedUri = null;

    public function enrolmentUri(): ?string
    {
        // Memoised for the render, and not as an optimisation. One render asks for this
        // four times — the @if, the QR writer, and both deep links — and minting on each
        // would hand the phone a different code from the one the QR encodes. Since the
        // card started offering a tap target beside the QR there are two ways to spend it
        // on screen at once, so a drift here would be a link that silently fails for
        // whoever picked the other one.
        if ($this->memoisedUri !== null) {
            return $this->memoisedUri;
        }

        if (! AuthenticatorClient::find() instanceof Client) {
            return null;
        }

        $subject = app(CurrentUser::class)->id();

        if ($subject === '') {
            return null;
        }

        return $this->memoisedUri = AuthenticatorClient::SCHEME.'://connect'
            .'?host='.urlencode(request()->getHost())
            .'&t='.urlencode(app(EnrolmentToken::class)->mint($subject));
    }

    /**
     * Seconds between re-renders of the code.
     *
     * Comfortably inside the code's own life, so nobody scans something that lapsed
     * while they were unlocking their phone. Derived from the TTL rather than written as
     * a number, so the two cannot drift apart.
     */
    public function refreshInterval(): int
    {
        return (int) floor(EnrolmentToken::TTL * 0.75);
    }

    /**
     * Rendered as inline SVG at currentColor so it follows the console's light/dark
     * theme, matching how the account page draws its TOTP code.
     */
    public function enrolmentQr(): ?string
    {
        $uri = $this->enrolmentUri();

        if ($uri === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd(),
        ));

        return $writer->writeString($uri);
    }

    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return Device::query()
            ->where('subject_id', app(CurrentUser::class)->id())
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Remove one of MY devices. Same semantics as the API's destroy: scoped to the
     * caller, so someone else's device behaves exactly like a missing one, and the
     * removal is audited.
     */
    public function remove(string $id): void
    {
        $me = app(CurrentUser::class);

        $device = Device::query()
            ->whereKey($id)
            ->where('subject_id', $me->id())
            ->first();

        if ($device === null) {
            return;
        }

        $device->delete();

        app(AuditLog::class)->record(new AuditEvent(
            action: 'device.removed',
            actorType: ActorType::User,
            actorId: $me->id(),
            targetType: 'device',
            targetId: $id,
            ip: request()->ip(),
        ));

        unset($this->devices);
        $this->dispatch('toast', message: 'Device removed.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Trusted devices"
                   subtitle="Phones enrolled in the authenticator app. They receive approval prompts and alerts when your account signs in somewhere new."
                   :help="App\Platform\Help\HelpTopic::TrustedDevices" />

    @if ($this->enrolmentUri() !== null)
        {{-- Re-rendered inside the code's own lifetime, so nobody ever scans one that
             lapsed while they were unlocking their phone. `wire:poll` re-runs the
             component, which mints a fresh code and redraws the QR. --}}
        <div class="card p-6" wire:poll.{{ $this->refreshInterval() }}s>
            {{-- Grid, not flex-wrap. `flex-1` is `flex-basis: 0`, so the text column
                 never reports that it does not fit — it just shrinks, and `flex-wrap`
                 has nothing to react to. On a phone that squeezed the instructions into
                 a column about ten characters wide, one word per line, beside a QR code
                 that kept its full size. The grid stacks below `sm` and only pairs them
                 when there is room for both. --}}
            <div class="grid gap-6 sm:grid-cols-[auto_minmax(0,1fr)] items-start">
                {{-- HIDDEN ON A PHONE, because you cannot scan the screen you are holding.
                     The card used to render the QR at every width and offer nothing else,
                     so opening this page on the device being enrolled — the single most
                     natural thing to do — reached a dead end. --}}
                <div class="hidden sm:block shrink-0 max-w-full" style="color:var(--foreground)">
                    {!! $this->enrolmentQr() !!}
                </div>

                <div class="min-w-0 space-y-3">
                    <h2 class="text-base font-medium" style="color:var(--foreground)">Add a phone</h2>

                    {{-- The instructions differ by which device you are on, so they are
                         written twice rather than hedged into one sentence that is
                         half-wrong everywhere. --}}
                    <p class="text-sm sm:hidden" style="color:var(--muted)">
                        Open <strong>Cbox ID</strong> on this phone to finish enrolling it.
                    </p>
                    <p class="hidden sm:block text-sm" style="color:var(--muted)">
                        Install <strong>Cbox ID</strong> on your phone, open it, and scan this
                        code. You'll then sign in with your normal account in the browser.
                    </p>

                    {{-- THE DEEP LINK, as a tap target rather than as printed text.
                         `wire:navigate` is deliberately absent: this is a custom scheme
                         handed to the OS, not a page Livewire can fetch. --}}
                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <a href="{{ $this->enrolmentUri() }}" class="btn btn-primary sm:hidden">
                            Open the Cbox ID app
                        </a>
                        {{-- `sm:block`, NOT `sm:inline`. In the compiled stylesheet
                             `.hidden` lands after the `sm:inline` rule at equal
                             specificity, so `display:none` wins and the link is in the
                             DOM at every width and drawn at none of them — invisible in a
                             way no assertion about the markup can see. `sm:block` is
                             emitted after `.hidden` and does override it. Verified in a
                             browser at 1280px, not reasoned about. --}}
                        <a href="{{ $this->enrolmentUri() }}" class="hidden sm:block text-sm underline" style="color:var(--muted)">
                            Reading this on the phone itself? Open the app
                        </a>
                        @if (is_string($storeUrl = config('id-devices.app_store_url')) && $storeUrl !== '')
                            <a href="{{ $storeUrl }}" target="_blank" rel="noopener noreferrer"
                               class="text-sm underline" style="color:var(--muted)">Get the app</a>
                        @endif
                    </div>

                    {{-- Said plainly, because a code on a screen invites the question —
                         and because the previous wording ("safe to share") stopped being
                         true the moment the code started carrying an identity. --}}
                    <p class="text-sm" style="color:var(--muted)">
                        This link expires after two minutes and only works once, for your account.
                        It refreshes on its own while this page is open — don't share a screenshot
                        of it.
                    </p>
                </div>
            </div>
        </div>
    @else
        {{-- Reached only when self-provisioning in mount() failed — the error is in the logs. --}}
        <div class="card p-6">
            <h2 class="text-base font-medium" style="color:var(--foreground)">Add a phone</h2>
            <p class="mt-2 text-sm" style="color:var(--muted)">
                Setting up the authenticator app for this environment didn't succeed. The error
                has been logged — reload this page to try again.
            </p>
        </div>
    @endif

    <div class="card" style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th class="px-4 py-3 font-medium">Device</th>
                    <th class="px-4 py-3 font-medium">Platform</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Last seen</th>
                    <th class="px-4 py-3 font-medium"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->devices as $device)
                    <tr>
                        <td class="px-4 py-3 font-medium" style="color:var(--foreground)">{{ $device->name }}</td>
                        <td class="px-4 py-3" style="color:var(--muted)">{{ $device->platform->label() }}</td>
                        <td class="px-4 py-3">
                            <span class="badge {{ $device->status === DeviceStatus::Active ? 'badge-success' : 'badge-warn' }}">
                                {{ $device->status->label() }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 mono" style="color:var(--muted)">
                            {{ $device->last_seen_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                wire:click="remove('{{ $device->id }}')"
                                wire:confirm="Remove {{ $device->name }}? It will stop receiving approval prompts and alerts."
                            >Remove</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center" style="color:var(--faint)">
                            No devices enrolled yet — use the card above to add your phone.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
