<?php

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\OAuthServer\Models\Client;

use App\Platform\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Styled with the host's CSS variables rather than Tailwind `dark:` utilities — the
 * console themes via a [data-theme] attribute, so a `dark:` class would render
 * permanently light and off-brand inside it.
 *
 * Class-based rather than functional Volt, matching the host's other admin pages,
 * because this page needs a boot() hook and Volt's functional boot() compiles to a
 * `void` method containing a `return`.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * Read gate re-checked on EVERY request, not just first mount — boot() runs on each
     * hydration, so an admin demoted mid-session cannot keep re-rendering the estate's
     * device inventory from a stale snapshot.
     *
     * This page shows every user's handsets and the errors their pushes produced, which
     * is precisely the reconnaissance an attacker wants: who is enrolled, on what, and
     * which devices are currently failing. Route middleware does not gate it —
     * `platform.auth` only proves a session exists and `console.feature` only checks a
     * flag — so without this any ordinary member could read the lot by typing the URL.
     */
    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    /**
     * The enrolment code the authenticator app scans.
     *
     * Carries only the HOST — no token, no identity, nothing time-limited. The app uses
     * it to find which Cbox ID to talk to, then runs a normal authorization-code sign-in
     * against it, so the code is safe on a screen, in a screenshot, or on a wiki. That is
     * deliberate: an enrolment code that WERE a credential would have to be short-lived
     * and single-use, and would turn "scan this" into a support problem.
     *
     * Null when no authenticator client is provisioned in this environment — there is
     * nothing for the app to connect to, so showing a code would be a dead end.
     */
    public function enrolmentUri(): ?string
    {
        if (! AuthenticatorClient::find() instanceof Client) {
            return null;
        }

        $host = request()->getHost();

        return AuthenticatorClient::SCHEME.'://connect?host='.urlencode($host);
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
        return Device::query()->orderByDesc('last_seen_at')->limit(100)->get();
    }

    /**
     * @return Collection<int, PushNotification>
     */
    #[Computed]
    public function recent(): Collection
    {
        return PushNotification::query()->latest('created_at')->limit(25)->get();
    }
}; ?>

<div class="space-y-6">
    <div class="cbx-page-header mb-6">
        <div class="min-w-0">
            <h1 class="cbx-page-title">Trusted devices</h1>
            <p class="cbx-page-desc">
                Handsets enrolled in the authenticator app. These receive approval prompts
                and sign-in alerts. Push tokens are never shown.
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
        <div class="card p-6">
            <h2 class="text-base font-medium" style="color:var(--foreground)">Add a phone</h2>
            <p class="mt-2 text-sm" style="color:var(--muted)">
                No authenticator app is set up for this environment yet. Run
                <span class="mono">php artisan cbox-id:devices:client</span> to provision one, then
                an enrolment code will appear here.
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
                    <th class="px-4 py-3 font-medium">Health</th>
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
                        <td class="px-4 py-3" style="color:var(--muted)">
                            @if ($device->circuit_opened_at !== null)
                                Paused after {{ $device->consecutive_failures }} failures
                            @elseif ($device->consecutive_failures > 0)
                                {{ $device->consecutive_failures }} recent failures
                            @else
                                Healthy
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center" style="color:var(--faint)">
                            No devices enrolled yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        <h2 class="mb-3 text-sm font-medium" style="color:var(--foreground)">Recent notifications</h2>

        <div class="card" style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Kind</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Attempts</th>
                        <th class="px-4 py-3 font-medium">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->recent as $notification)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 mono" style="color:var(--muted)">
                                {{ $notification->created_at?->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3" style="color:var(--foreground)">{{ $notification->kind->label() }}</td>
                            <td class="px-4 py-3">
                                <span class="badge {{ $notification->status === NotificationStatus::Delivered ? 'badge-success' : ($notification->status->isTerminal() ? 'badge-danger' : 'badge-warn') }}">
                                    {{ $notification->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 tabular-nums mono" style="color:var(--muted)">{{ $notification->attempt }}</td>
                            <td class="px-4 py-3" style="color:var(--muted)">{{ $notification->last_error ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center" style="color:var(--faint)">
                                Nothing sent yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
