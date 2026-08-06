<?php

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;
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
new #[Layout('components.layouts.console', ['title' => 'Trusted devices'])] class extends Component
{
    /**
     * Read gate re-checked on EVERY request, not just first mount — boot() runs on each
     * hydration, so an admin demoted mid-session cannot keep re-rendering the estate's
     * device inventory from a stale snapshot.
     *
     * This page shows every user's handsets and the errors their pushes produced, which
     * is precisely the reconnaissance an attacker wants: who is enrolled, on what, and
     * which devices are currently failing. Route middleware does not gate it by role —
     * the session gate only proves a session exists and `console.feature` only checks a
     * flag — so without this any ordinary member could read the lot by typing the URL.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        // Confined to the acting organization's members.
        //
        // `Device` is keyed by subject, so an unqualified read on an ORGANIZATION-gated
        // page handed an admin of one tenant every other tenant's handset names, models,
        // OS versions and health. The page's own docblock calls that "precisely the
        // reconnaissance an attacker wants", which was true of the page itself.
        return $this->scoped(Device::query())
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();
    }

    /**
     * Narrow a device query to the acting organization's members.
     *
     * Membership ids are fetched through the contract with an explicit organization id,
     * NOT as a subquery on the model. `Membership` carries a tenant scope that denies by
     * default when no tenant context is set, so a subquery silently returns nothing and
     * the page shows an empty inventory — a filter that fails to a blank screen rather
     * than to a refusal, and one that depends on ambient state a security decision should
     * not rest on. Measured: under the console's own test harness the scoped query
     * returned zero rows while the devices existed.
     *
     * With no organization resolved — only reachable by an administrator who holds the
     * environment — the estate is the environment's own, which `Device`'s environment
     * scope still bounds. That is the inventory the person who owns the environment is
     * entitled to, and it is not reachable from the organization plane: the scope refuses
     * rather than answering null there.
     *
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    private function scoped(Builder $query): Builder
    {
        $organizationId = app(ConsoleScope::class)->organizationId();

        if ($organizationId === null) {
            return $query;
        }

        $memberIds = app(Memberships::class)
            ->forOrganization($organizationId)
            ->map(static fn (Membership $membership): string => $membership->user_id)
            ->values()
            ->all();

        return $query->whereIn('subject_id', $memberIds);
    }

    /**
     * @return Collection<int, PushNotification>
     */
    #[Computed]
    public function recent(): Collection
    {
        // Same confinement: a push record carries the device it went to and the error
        // text if it failed.
        return PushNotification::query()
            ->whereIn('device_id', $this->scoped(Device::query())->select('id'))
            ->latest('created_at')
            ->limit(25)
            ->get();
    }

    /**
     * The view half. `devices.mine` is the ORGANIZATION plane's page — a subject's own
     * handsets — and does not exist on the other one, so linking to it unconditionally
     * would throw a RouteNotFoundException and 500 the page for the very administrator
     * this merge is for.
     *
     * @return array{personalPage: string|null, wholeEnvironment: bool}
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);

        return [
            'personalPage' => Route::has($scope->routeName('devices.mine'))
                ? route($scope->routeName('devices.mine'))
                : null,
            'wholeEnvironment' => $scope->organizationId() === null,
        ];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Trusted devices"
                   subtitle="Handsets enrolled in the authenticator app{{ $wholeEnvironment ? ' across this environment' : ' by this organization\'s members' }}. These receive approval prompts and sign-in alerts. Push tokens are never shown."
                   :help="App\Platform\Help\HelpTopic::TrustedDevices" />

    {{-- Enrolment is personal, so it lives where every user can reach it — on the plane
         where "every user" means something. An environment administrator is not a subject
         of this environment and has no page here to be sent to, so the link is absent
         rather than broken. --}}
    @if ($personalPage !== null)
        <p class="text-sm" style="color:var(--muted)">
            Anyone can enrol their own phone from
            <a href="{{ $personalPage }}" wire:navigate class="underline">My account → Trusted devices</a>.
        </p>
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
