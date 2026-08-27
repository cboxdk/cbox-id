<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Inertia\Response;

/**
 * ONE USAGE PAGE FOR BOTH PLANES.
 *
 * The environment plane had its own — `environment.analytics` — over the SAME counters, and
 * it was the primitive version of this one: raw `auth.*` metric keys with no label table,
 * no time window, no series. So the same numbers were called "Usage" on one plane and
 * "Analytics" on the other, and only one of them was legible.
 *
 * The scope is the only real difference, and the meter already had it: `snapshot()` takes a
 * nullable organization id, where null totals the metric across the WHOLE environment.
 * {@see ConsoleScope::organizationId()} answers null on the
 * environment plane for exactly that reason, so the page asks one question and each plane
 * answers it in its own terms.
 */
final readonly class UsageController extends ConsoleController
{
    /** The window this page is about. Thirty days is a month of habit, not a quarter. */
    private const DAYS = 30;

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

    public function index(UsageMeter $meter): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * Null on the environment plane — which the meter reads as "the whole environment",
         * not as "no data". That is the entire difference between this page and the
         * analytics page it replaces.
         */
        $organizationId = $this->scope->organizationId();
        $environmentWide = $organizationId === null;

        $until = now();
        $since = $until->copy()->subDays(self::DAYS - 1)->startOfDay();

        $snapshot = $meter->snapshot($organizationId, $since, $until);
        arsort($snapshot);

        // A dense series, zero-filled: a chart that skips the days with no sign-ins draws a
        // quiet week as a busy one.
        $raw = $meter->series('auth.login', $organizationId, $since, $until);
        $series = [];

        for ($day = $since->copy(); $day <= $until; $day->addDay()) {
            $series[] = [
                'day' => $day->format('Y-m-d'),
                'label' => $day->format('M j'),
                'value' => (int) ($raw[$day->format('Y-m-d')] ?? 0),
            ];
        }

        $metrics = [];

        foreach ($snapshot as $metric => $total) {
            $key = (string) $metric;

            $metrics[] = [
                'key' => $key,
                // The raw key stays beside the label everywhere it is shown: the person
                // reading this may also be the one who has to go and find it in the meter.
                'label' => self::LABELS[$key] ?? ucfirst(str_replace(['auth.', '_'], ['', ' '], $key)),
                'total' => (int) $total,
            ];
        }

        return $this->page('console/usage', 'Usage', [
            'organization' => $environmentWide ? null : app(CurrentUser::class)->organization()?->name,
            'environmentWide' => $environmentWide,
            'metrics' => $metrics,
            'series' => $series,
            'window' => [
                'from' => $since->format('M j'),
                'to' => $until->format('M j'),
            ],
            'help' => HelpProps::for(HelpTopic::Usage),
        ]);
    }
}
