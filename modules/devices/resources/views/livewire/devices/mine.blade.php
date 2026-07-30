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
     * Carries only the HOST — no token, no identity, nothing time-limited. The app uses
     * it to find which Cbox ID to talk to, then runs a normal authorization-code
     * sign-in against it, so the code is safe on a screen, in a screenshot, or on a
     * wiki. Null only when provisioning failed in mount() — nothing to connect to, so
     * a code would be a dead end.
     */
    public function enrolmentUri(): ?string
    {
        if (! AuthenticatorClient::find() instanceof Client) {
            return null;
        }

        return AuthenticatorClient::SCHEME.'://connect?host='.urlencode(request()->getHost());
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
    <div class="cbx-page-header mb-6">
        <div class="min-w-0">
            <h1 class="cbx-page-title">Trusted devices</h1>
            <p class="cbx-page-desc">
                Phones enrolled in the authenticator app. They receive approval prompts
                and alerts when your account signs in somewhere new.
            </p>
        </div>
    </div>

    @if ($this->enrolmentUri() !== null)
        <div class="card p-6">
            <div class="flex flex-wrap items-start gap-6">
                <div class="shrink-0" style="color:var(--foreground)">
                    {!! $this->enrolmentQr() !!}
                </div>

                <div class="min-w-0 flex-1 space-y-2">
                    <h2 class="text-base font-medium" style="color:var(--foreground)">Add a phone</h2>
                    <p class="text-sm" style="color:var(--muted)">
                        Install <strong>Cbox ID</strong> from the App Store, open it, and scan this
                        code. You'll then sign in with your normal account in the browser.
                    </p>
                    {{-- Said plainly, because a code on a screen invites the question. --}}
                    <p class="text-sm" style="color:var(--muted)">
                        The code only says which Cbox ID to connect to — it grants nothing on its
                        own, so it's safe to share or leave on screen.
                    </p>
                    <p class="mono text-xs" style="color:var(--faint)">{{ $this->enrolmentUri() }}</p>
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
                            No devices enrolled yet — scan the code above to add your phone.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
