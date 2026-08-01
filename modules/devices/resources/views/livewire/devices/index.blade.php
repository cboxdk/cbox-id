<?php

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;

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
new #[Layout('components.layouts.app', ['title' => 'Trusted devices'])] class extends Component
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
    <x-page-header title="Trusted devices"
                   subtitle="Handsets enrolled in the authenticator app. These receive approval prompts and sign-in alerts. Push tokens are never shown." />

    {{-- Enrolment is personal, so it lives where every user can reach it. --}}
    <p class="text-sm" style="color:var(--muted)">
        Anyone can enrol their own phone from
        <a href="{{ route('devices.mine') }}" wire:navigate class="underline">My account → Trusted devices</a>.
    </p>

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
