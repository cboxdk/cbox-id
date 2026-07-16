<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Usage'])] class extends Component
{
    /** Human labels for the shared auth.* metric keys. */
    private const LABELS = [
        'auth.login' => 'Sign-ins',
        'auth.session' => 'Sessions',
        'auth.user' => 'Users created',
        'auth.id_token' => 'Tokens issued',
        'auth.mfa_enrolled' => 'MFA enrolments',
        'auth.passkey' => 'Passkeys registered',
        'auth.passkey_auth' => 'Passkey sign-ins',
        'auth.otp' => 'One-time codes',
        'auth.identity_linked' => 'Identities linked',
        'auth.organization' => 'Organisations created',
        'auth.member_added' => 'Members added',
        'auth.invitation' => 'Invitations sent',
        'auth.invitation_accepted' => 'Invitations accepted',
        'auth.role_assigned' => 'Roles assigned',
        'auth.service_account' => 'Service accounts',
        'auth.ciba' => 'Agent approvals',
        'auth.domain_verified' => 'Domains verified',
        'auth.governance_campaign' => 'Access reviews',
        'auth.scim_sync' => 'SCIM syncs',
        'auth.vault_lease' => 'Vault leases',
    ];

    public function with(): array
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();
        $meter = app(UsageMeter::class);

        $until = now();
        $since = $until->copy()->subDays(29)->startOfDay();

        $snapshot = $orgId !== null ? $meter->snapshot($orgId, $since, $until) : [];
        arsort($snapshot);

        // A 30-day dense series for sign-ins, zero-filled for the sparkline/bars.
        $rawSeries = $orgId !== null ? $meter->series('auth.login', $orgId, $since, $until) : [];
        $series = [];
        for ($day = $since->copy(); $day <= $until; $day->addDay()) {
            $key = $day->format('Y-m-d');
            $series[$key] = $rawSeries[$key] ?? 0;
        }

        return [
            'org' => $me->organization(),
            'labels' => self::LABELS,
            'snapshot' => $snapshot,
            'series' => $series,
            'seriesMax' => $series === [] ? 0 : max($series),
            'since' => $since,
            'until' => $until,
        ];
    }
}; ?>

<div>
    <x-page-header title="Usage"
                   subtitle="Activity across {{ $org?->name ?? 'your organization' }} — last 30 days. This is analytics; the SaaS bills separately." />

    @if ($snapshot === [])
        <div class="card p-10 text-center">
            <span class="grid place-items-center rounded-full mx-auto mb-4" style="width:2.75rem;height:2.75rem;background:var(--accent-soft);color:var(--accent)">
                <x-icon name="audit" class="w-5 h-5" />
            </span>
            <p class="font-semibold">No activity recorded yet</p>
            <p class="mt-1 text-sm" style="color:var(--faint)">Usage counters fill as your team signs in, invites members, and issues tokens.</p>
        </div>
    @else
        {{-- Headline metric cards --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (array_slice($snapshot, 0, 8, true) as $metric => $total)
                <div class="card p-5">
                    <div class="text-sm truncate" style="color:var(--muted)">{{ $labels[$metric] ?? $metric }}</div>
                    <p class="mt-2 text-3xl font-semibold tracking-tight mono">{{ number_format($total) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Sign-ins over time --}}
        <div class="card mt-4">
            <div class="px-5 py-4 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="font-semibold">Sign-ins over time</h3>
                <span class="text-xs mono" style="color:var(--faint)">{{ $since->format('M j') }} – {{ $until->format('M j') }}</span>
            </div>
            <div class="px-5 py-5">
                <div class="flex items-end gap-[3px]" style="height:120px" role="img" aria-label="Daily sign-ins, last 30 days">
                    @foreach ($series as $day => $value)
                        <div class="flex-1 rounded-t"
                             title="{{ \Illuminate\Support\Carbon::parse($day)->format('M j') }}: {{ $value }}"
                             style="height:{{ $seriesMax > 0 ? max(2, (int) round($value / $seriesMax * 100)) : 2 }}%;background:{{ $value > 0 ? 'var(--accent)' : 'var(--border)' }};min-height:2px"></div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Full breakdown --}}
        <div class="card mt-4">
            <div class="px-5 py-4 border-b" style="border-color:var(--border)">
                <h3 class="font-semibold">All metrics</h3>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($snapshot as $metric => $total)
                        <tr class="border-b" style="border-color:var(--border)">
                            <td class="px-5 py-2.5">{{ $labels[$metric] ?? $metric }}</td>
                            <td class="px-5 py-2.5 text-xs mono" style="color:var(--faint)">{{ $metric }}</td>
                            <td class="px-5 py-2.5 text-right mono font-medium">{{ number_format($total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
