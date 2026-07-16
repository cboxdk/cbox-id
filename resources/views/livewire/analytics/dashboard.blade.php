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

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Analytics</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Authentication activity over the last {{ $this->overview['window'] }} days, from the platform's event stream.
        </p>
    </header>

    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ($this->overview['tiles'] as $tile)
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $tile['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">{{ number_format($tile['total']) }}</p>

                <div class="mt-3 flex h-10 items-end gap-px" role="img" aria-label="{{ $tile['label'] }} over time">
                    @foreach ($tile['bars'] as $bar)
                        <span
                            class="flex-1 rounded-sm bg-indigo-500/80 dark:bg-indigo-400/80"
                            style="height: {{ max(2, (int) round($bar['count'] / $tile['max'] * 100)) }}%"
                            title="{{ $bar['day'] }}: {{ $bar['count'] }}"
                        ></span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 sm:max-w-md">
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Active organizations</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">{{ number_format($this->overview['active_orgs']) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">MFA rate</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">{{ $this->overview['mfa_rate'] }}%</p>
        </div>
    </div>
</div>
