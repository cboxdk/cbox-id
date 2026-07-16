<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Overview'])] class extends Component
{
    public function with(): array
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();

        $connection = $orgId !== null ? app(Connections::class)->forOrganization($orgId) : null;

        $recent = $orgId !== null
            ? AuditEntry::query()->where('organization_id', $orgId)->orderByDesc('sequence')->limit(6)->get()
            : collect();

        return [
            'me' => $me,
            'memberCount' => $orgId !== null ? app(Memberships::class)->forOrganization($orgId)->count() : 0,
            'ssoActive' => $connection !== null,
            'recent' => $recent,
            // Resolve opaque target ids to human names so the feed reads like a
            // story ("member added · Ada Lovelace"), not a wall of ULIDs.
            'targetLabels' => $this->resolveTargets($recent, $orgId, $me->organization()?->name),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditEntry>  $entries
     * @return array<string, string>
     */
    private function resolveTargets($entries, ?string $orgId, ?string $orgName): array
    {
        $subjects = app(Subjects::class);
        $labels = [];

        foreach ($entries as $entry) {
            $id = $entry->target_id;

            if (! is_string($id) || $id === '' || isset($labels[$id])) {
                continue;
            }

            if ($entry->target_type === 'user') {
                $subject = $subjects->find($id);
                $name = $subject?->name ?? $subject?->email;
                if (is_string($name) && $name !== '') {
                    $labels[$id] = $name;
                }
            } elseif ($entry->target_type === 'organization' && $id === $orgId && is_string($orgName)) {
                $labels[$id] = $orgName;
            }
        }

        return $labels;
    }
}; ?>

<div>
    <x-page-header :title="'Welcome back, '.\Illuminate\Support\Str::before($me->name(), ' ')"
                   subtitle="Here's what's happening across {{ $me->organization()?->name ?? 'your organization' }}." />

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="members" class="w-4 h-4" /> Members</div>
            <p class="mt-2 text-3xl font-semibold tracking-tight mono">{{ $memberCount }}</p>
        </div>
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="connections" class="w-4 h-4" /> Enterprise SSO</div>
            <p class="mt-2 text-lg font-semibold">
                @if ($ssoActive) <span class="badge badge-success">Active</span> @else <span class="badge">Not configured</span> @endif
            </p>
        </div>
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="shield" class="w-4 h-4" /> Your role</div>
            <p class="mt-2 text-lg font-semibold">{{ ucfirst($me->role() ?? 'member') }}</p>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3 mt-4">
        <div class="card lg:col-span-2">
            <div class="px-5 py-4 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="font-semibold">Recent activity</h3>
                <a href="{{ route('audit') }}" class="text-sm" style="color:var(--accent)">View audit log</a>
            </div>
            @if ($recent->isEmpty())
                <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">No activity recorded yet.</div>
            @else
                <ul>
                    @foreach ($recent as $entry)
                        <li class="px-5 py-3 border-b flex items-center justify-between gap-4" style="border-color:var(--border)">
                            <div class="min-w-0">
                                <p class="text-sm font-medium truncate">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</p>
                                @php $label = $targetLabels[$entry->target_id] ?? null; @endphp
                                <p class="text-xs truncate" style="color:var(--faint)">
                                    @if ($label)
                                        {{ $label }}
                                    @elseif ($entry->target_id)
                                        <span class="mono">{{ $entry->target_type }} {{ \Illuminate\Support\Str::limit($entry->target_id, 12) }}</span>
                                    @endif
                                </p>
                            </div>
                            <time class="text-xs whitespace-nowrap" style="color:var(--faint)">{{ $entry->recorded_at?->diffForHumans() }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card p-5">
            <h3 class="font-semibold">Set up your platform</h3>
            <ul class="mt-4 space-y-3">
                <li class="flex items-start gap-3">
                    <span class="grid place-items-center rounded-full mt-0.5" style="width:1.25rem;height:1.25rem;background:var(--success-soft);color:var(--success)"><x-icon name="check" class="w-3 h-3" /></span>
                    <div><p class="text-sm font-medium">Organization created</p></div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="grid place-items-center rounded-full mt-0.5" style="width:1.25rem;height:1.25rem;border:1px solid var(--border)"></span>
                    <div><a href="{{ route('members') }}" class="text-sm font-medium" style="color:var(--accent)">Invite your team →</a></div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="grid place-items-center rounded-full mt-0.5" style="width:1.25rem;height:1.25rem;{{ $ssoActive ? 'background:var(--success-soft);color:var(--success)' : 'border:1px solid var(--border)' }}">
                        @if ($ssoActive)<x-icon name="check" class="w-3 h-3" />@endif
                    </span>
                    <div><a href="{{ route('connections') }}" class="text-sm font-medium" style="color:var(--accent)">Connect enterprise SSO →</a></div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="grid place-items-center rounded-full mt-0.5" style="width:1.25rem;height:1.25rem;border:1px solid var(--border)"></span>
                    <div><a href="{{ route('directories') }}" class="text-sm font-medium" style="color:var(--accent)">Enable SCIM provisioning →</a></div>
                </li>
            </ul>
        </div>
    </div>
</div>
