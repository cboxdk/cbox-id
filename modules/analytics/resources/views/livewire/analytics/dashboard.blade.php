<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Analytics'])] class extends Component
{

    /**
     * Route middleware does not gate this page: the module routes carry `platform.auth`
     * (a session exists) and `console.feature` (the flag is on), and neither is a role
     * check. The nav hides the area from a plain member, which is styling, not
     * authorization — the URL is typeable. Guarded in boot() rather than mount() so it
     * re-runs on every Livewire message, not just the first render.
     */
    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
    /**
     * @return array{window: int, tiles: list<array{label: string, total: int, bars: list<array{day: string, count: int}>, max: int}>, mfa_rate: int, unavailable: bool}
     */
    private function overview(): array
    {
        $configured = config('id-analytics.chart.window_days', 30);
        $window = max(1, is_numeric($configured) ? (int) $configured : 30);
        $until = Carbon::now();
        $since = $until->copy()->subDays($window - 1);

        $definitions = [
            ['key' => 'auth.login', 'label' => 'Logins'],
            ['key' => 'auth.id_token', 'label' => 'Tokens issued'],
            ['key' => 'auth.user', 'label' => 'New users'],
            ['key' => 'auth.mfa_enrolled', 'label' => 'MFA enrolments'],
        ];

        // A console page must never hard-500 because its data backend is missing or
        // unreachable — the dashboard CARD already degrades to empty on any reader
        // error, and the full page follows the same "never a broken dashboard"
        // doctrine. The underlying exception is still reported so operators can see
        // WHY (e.g. no usage schema yet, or an unreachable ClickHouse DSN).
        try {
            $reader = app(ReportReader::class);

            // THIS organization, never null.
            //
            // `null` means environment-wide in every one of these calls, and this page is
            // gated on an ORGANIZATION-level role — so an admin of one tenant was reading
            // every other tenant's sign-ins, tokens issued, new users and MFA enrolments.
            // The environment-wide view of the same data already exists, one plane up, at
            // Environment › Analytics behind EnvironmentAdminAuth.
            $organizationId = app(CurrentUser::class)->organization()?->id;

            if ($organizationId === null) {
                abort(403);
            }

            $tiles = [];
            foreach ($definitions as $definition) {
                $series = $reader->series($definition['key'], $organizationId, $since, $until);

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
                    // $window is floored at 1, so there is always at least one bar.
                    'max' => max(1, max($counts)),
                ];
            }

            $snapshot = $reader->snapshot($organizationId, $since, $until);
            $logins = $snapshot['auth.login'] ?? 0;
            $mfa = $snapshot['auth.mfa_enrolled'] ?? 0;
            return [
                'window' => $window,
                'tiles' => $tiles,
                'mfa_rate' => $logins > 0 ? (int) round(($mfa / $logins) * 100) : 0,
                'unavailable' => false,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'window' => $window,
                'tiles' => [],
                'mfa_rate' => 0,
                'unavailable' => true,
            ];
        }
        }

    /**
     * @return array{overview: array{window: int, tiles: list<array{label: string, total: int, bars: list<array{day: string, count: int}>, max: int}>, mfa_rate: int, unavailable: bool}}
     */
    public function with(): array
    {
        return ['overview' => $this->overview()];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Analytics"
                   subtitle="Authentication activity over the last {{ $overview['window'] }} days, from the platform's event stream." />

    @if ($overview['unavailable'])
        <div class="card">
            <div class="cbx-empty">
                <div class="cbx-empty-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5" aria-hidden="true">
                        <path d="M3 3v18h18"/><path d="M18.75 8.25v9M12.75 11.25v6M6.75 14.25v3"/>
                    </svg>
                </div>
                <h3>Analytics isn't available yet</h3>
                <p>No analytics data source is configured or reachable. Once the platform is recording usage — or a ClickHouse DSN is set — activity will appear here.</p>
            </div>
        </div>
    @else
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ($overview['tiles'] as $tile)
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

    {{-- "Active organizations" is gone: a count of the OTHER tenants sharing this
         environment has no organization-scoped meaning, and reporting it to one of them
         is a disclosure rather than a metric. It belongs on the environment plane, which
         already has its own analytics page. --}}
    <div class="grid grid-cols-1 gap-4 sm:max-w-md">
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide" style="color:var(--muted)">MFA rate</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums mono" style="color:var(--foreground)">{{ $overview['mfa_rate'] }}%</p>
        </div>
    </div>
    @endif
</div>
