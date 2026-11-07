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
 * The environment plane had its own — `environment.analytics`, now `environment.usage` — over the SAME counters, and
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
    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'auth.login' => __('console.sign_ins_f258cdd8'),
            'auth.session' => __('console.sessions_6fa3cbf4'),
            'auth.user' => __('console.users_created_e2423242'),
            'auth.mfa_enrolled' => __('console.mfa_enrolments_57ec5e68'),
            'auth.passkey' => __('console.passkeys_registered_dcd6aabb'),
            'auth.passkey_auth' => __('console.passkey_sign_ins_aa160ea2'),
            'auth.otp' => __('console.one_time_codes_832b4020'),
            'auth.identity_linked' => __('console.identities_linked_b4e2cb8c'),
            'auth.organization' => __('console.organisations_created_e52be337'),
            'auth.member_added' => __('console.members_added_fa393672'),
            'auth.member_removed' => __('console.members_removed_0604a637'),
            'auth.directory_user' => __('console.directory_users_provisioned_18e1ea18'),
            'auth.invitation' => __('console.invitations_sent_3bdf2724'),
            'auth.invitation_accepted' => __('console.invitations_accepted_831b4800'),
            'auth.role_assigned' => __('console.roles_assigned_f07357c6'),
            'auth.service_account' => __('console.service_accounts_1642d9e2'),
            'auth.ciba' => __('console.approvals_granted_35195475'),
            'auth.domain_verified' => __('console.domains_verified_234eeb46'),
            'auth.governance_campaign' => __('console.access_reviews_f0cc91be'),
        ];
    }

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
                'label' => self::labels()[$key] ?? ucfirst(str_replace(['auth.', '_'], ['', ' '], $key)),
                'total' => (int) $total,
            ];
        }

        return $this->page('console/usage', __('console.usage_8d59829c'), [
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
