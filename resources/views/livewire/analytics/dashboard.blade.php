<?php

use Cbox\Id\Analytics\Contracts\ReportReader;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, layout};
layout('components.layouts.app');

$overview = computed(function () {
    $reader = app(ReportReader::class);

    $window = max(1, (int) config('id-analytics.chart.window_days', 30));
    $until = Carbon::now();
    $since = $until->copy()->subDays($window - 1);

    $definitions = [
        ['key' => 'auth.login', 'label' => 'Logins'],
        ['key' => 'auth.id_token', 'label' => 'Tokens issued'],
        ['key' => 'auth.user', 'label' => 'New users'],
        ['key' => 'auth.mfa_enrolled', 'label' => 'MFA enrolments'],
    ];

    $tiles = [];
    foreach ($definitions as $definition) {
        $series = $reader->series($definition['key'], null, $since, $until);

        $bars = [];
        for ($i = 0; $i < $window; $i++) {
            $day = $since->copy()->addDays($i)->format('Y-m-d');
            $bars[] = ['day' => $day, 'count' => (int) ($series[$day] ?? 0)];
        }

        $counts = array_column($bars, 'count');

        $tiles[] = [
            'label' => $definition['label'],
            'total' => array_sum($counts),
            'bars' => $bars,
            'max' => max(1, $counts === [] ? 0 : max($counts)),
        ];
    }

    $logins = $reader->snapshot(null, $since, $until)['auth.login'] ?? 0;
    $mfa = $reader->snapshot(null, $since, $until)['auth.mfa_enrolled'] ?? 0;
    $activeOrgs = count($reader->topOrganizations('auth.login', $since, $until, 1000));

    return [
        'window' => $window,
        'tiles' => $tiles,
        'active_orgs' => $activeOrgs,
        'mfa_rate' => $logins > 0 ? (int) round(($mfa / $logins) * 100) : 0,
    ];
});

?>

<div class="space-y-6">
    <header class="cbx-page-header">
        <h1 class="cbx-page-title">Analytics</h1>
        <p class="cbx-page-desc">
            Authentication activity over the last {{ $this->overview['window'] }} days, from the platform's event stream.
        </p>
    </header>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ($this->overview['tiles'] as $tile)
            <div class="card p-4">
                <p class="text-xs font-medium uppercase tracking-wide" style="color:var(--muted)">{{ $tile['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums mono" style="color:var(--foreground)">{{ number_format($tile['total']) }}</p>

                <div class="mt-3 flex h-10 items-end gap-px" role="img" aria-label="{{ $tile['label'] }} over time">
                    @foreach ($tile['bars'] as $bar)
                        <span
                            class="flex-1 rounded-sm"
                            style="height: {{ max(2, (int) round($bar['count'] / $tile['max'] * 100)) }}%; background:var(--accent)"
                            title="{{ $bar['day'] }}: {{ $bar['count'] }}"
                        ></span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 sm:max-w-md">
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide" style="color:var(--muted)">Active organizations</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums mono" style="color:var(--foreground)">{{ number_format($this->overview['active_orgs']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide" style="color:var(--muted)">MFA rate</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums mono" style="color:var(--foreground)">{{ $this->overview['mfa_rate'] }}%</p>
        </div>
    </div>
</div>
