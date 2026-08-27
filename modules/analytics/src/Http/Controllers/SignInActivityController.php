<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Illuminate\Support\Carbon;
use Inertia\Response;
use Throwable;

/**
 * CONSOLE › SIGN-IN ACTIVITY — one page, both planes.
 *
 * Sign-ins, tokens issued, new users and MFA enrolments over a rolling window, for the
 * organization the scope resolves. On the environment plane with no organization chosen
 * that is the environment's own total, which is the reader's documented meaning for a null
 * organization and the view the person who holds the environment is entitled to.
 *
 * NOT the environment console's own Usage page, which aggregates usage counters by metric.
 * That one answers "how much is this environment doing"; this one answers "who is signing
 * in, and how" — for one tenant at a time when one is chosen.
 */
final readonly class SignInActivityController extends ConsoleController
{
    /** The four questions this page is for, in the order somebody asks them. */
    private const METRICS = [
        ['key' => 'auth.login', 'label' => 'Logins'],
        ['key' => 'auth.id_token', 'label' => 'Tokens issued'],
        ['key' => 'auth.user', 'label' => 'New users'],
        ['key' => 'auth.mfa_enrolled', 'label' => 'MFA enrolments'],
    ];

    public function __invoke(): Response
    {
        $this->scope->assertMayAdminister();

        $configured = config('id-analytics.chart.window_days', 30);
        $window = max(1, is_numeric($configured) ? (int) $configured : 30);

        $until = Carbon::now();
        $since = $until->copy()->subDays($window - 1);

        /*
         * A CONSOLE PAGE MUST NEVER HARD-500 BECAUSE ITS DATA BACKEND IS MISSING. The
         * dashboard card already degrades to nothing on any reader error, and the full page
         * follows the same doctrine — the underlying exception is still reported, so an
         * operator can see WHY (no usage schema yet, an unreachable ClickHouse DSN).
         */
        try {
            $reader = app(ReportReader::class);

            /*
             * The acting organization, or null — and null means exactly one thing.
             *
             * `null` is environment-wide in every one of these reader calls. Read off the
             * signed-in user it ALSO meant "this member has no organization", and those two
             * readings collapsing into one answer is how an admin of one tenant read every
             * other tenant's sign-ins, tokens issued, new users and MFA enrolments. The
             * scope refuses rather than returning null on the organization plane, so the
             * environment-wide branch is reachable only by somebody who holds the
             * environment and has not narrowed to one of its organizations.
             */
            $organizationId = $this->scope->organizationId();

            $tiles = [];

            foreach (self::METRICS as $metric) {
                $series = $reader->series($metric['key'], $organizationId, $since, $until);

                $bars = [];

                for ($day = 0; $day < $window; $day++) {
                    $date = $since->copy()->addDays($day)->format('Y-m-d');
                    $bars[] = ['day' => $date, 'count' => (int) ($series[$date] ?? 0)];
                }

                $counts = array_column($bars, 'count');

                $tiles[] = [
                    'key' => $metric['key'],
                    'label' => $metric['label'],
                    'total' => array_sum($counts),
                    'bars' => $bars,
                    // The window is floored at 1, so there is always at least one bar.
                    'max' => max(1, max($counts)),
                ];
            }

            $snapshot = $reader->snapshot($organizationId, $since, $until);
            $logins = (int) ($snapshot['auth.login'] ?? 0);
            $mfa = (int) ($snapshot['auth.mfa_enrolled'] ?? 0);

            return $this->page('analytics::sign-in-activity', 'Sign-in activity', [
                'window' => $window,
                'tiles' => $tiles,
                'mfaRate' => $logins > 0 ? (int) round(($mfa / $logins) * 100) : 0,
                'unavailable' => false,
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->page('analytics::sign-in-activity', 'Sign-in activity', [
                'window' => $window,
                'tiles' => [],
                'mfaRate' => 0,
                'unavailable' => true,
            ]);
        }
    }
}
