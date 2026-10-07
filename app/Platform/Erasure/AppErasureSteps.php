<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Actions\Users\EraseUser;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\Contracts\ErasureSteps;
use Cbox\Id\Identity\Contracts\SubjectEraser;
use Illuminate\Contracts\Foundation\Application;

/**
 * The stores THIS APP keeps a person in, added to the framework's erasure pipeline so one
 * call to {@see SubjectEraser} — {@see EraseUser} — reaches them too, in the same
 * transaction, and rolls back with it.
 *
 * The framework erases what the framework owns. It cannot know about tables an app adds,
 * and a right-to-erasure request answered "done" while an app table still names the person
 * is the failure the whole pipeline exists to prevent. So every app table keyed by a
 * subject, or holding their address, has a step here — or a sentence below saying why not.
 *
 * Covered:
 *
 *  - {@see LoginTicketsErasureStep}: embedded sign-in tickets, each naming the subject.
 *  - {@see OnboardingErasureStep}: which setup checklists the person put away.
 *  - {@see RiskDecisionsErasureStep}: the keyed pseudonym of their address on the risk
 *    trail, unlinked (the decision rows stay, they are what thresholds are tuned on).
 *
 * The modules register their own beside the tables they add — the devices module its
 * handsets and enrolment codes, risk-plus its review trail — the same way the framework's
 * modules do.
 *
 * Deliberately NOT covered, because the person is not in them:
 *
 *  - `invitation_contexts` holds an invitation's return address and the INVITER's name; the
 *    invitation addressed to the person is the framework's, and its step deletes it.
 *  - `action_idempotency_records` and `action_approval_requests` are keyed by the
 *    management key or console administrator that asked, never by the person acted on.
 *  - `sessions` is Laravel's own store; the identity sessions it points at are revoked by
 *    the framework's `identity.sessions` step, after which a row is a dead cookie.
 *  - `admin_portal_links`, `id_analytics_events` and the compliance module's export runs
 *    are keyed by organization or environment.
 */
final class AppErasureSteps
{
    /** @var list<class-string<ErasureStep>> */
    public const array STEPS = [
        LoginTicketsErasureStep::class,
        OnboardingErasureStep::class,
        RiskDecisionsErasureStep::class,
    ];

    public static function register(ErasureSteps $steps, Application $app): void
    {
        foreach (self::STEPS as $step) {
            $steps->register($app->make($step));
        }
    }
}
