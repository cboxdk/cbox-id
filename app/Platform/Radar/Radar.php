<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\RiskGuard;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RADAR — the decision on top of the score.
 *
 * {@see RiskGuard} scores an attempt; this turns the score and everything else known about the
 * attempt into allow, challenge or block, under the environment's own rules and mode. And it
 * remembers, after a sign-in SUCCEEDS, the device and the coarse location it succeeded from —
 * the history "new device" and "impossible travel" are measured against.
 *
 * FAIL OPEN, TO THE SCORE. If Radar itself cannot run — a table not migrated, a broken rule
 * row — the verdict falls back to what the risk score alone says, under the deployment's mode:
 * exactly the behaviour before Radar existed. It never fails a sign-in on its own account.
 */
final readonly class Radar
{
    public function __construct(
        private RadarPolicy $policy,
        private RadarSignals $signals,
        private RadarDevices $devices,
        private RadarPseudonyms $pseudonyms,
        private IpIntelligence $intelligence,
        private Subjects $subjects,
    ) {}

    public function assess(Request $request, RadarFlow $flow, RadarMethod $method, ?string $email, RiskAssessment $assessment): RadarDecision
    {
        try {
            // In a savepoint. On PostgreSQL a failed statement poisons the transaction it
            // ran in, so a Radar read that fails inside a caller's transaction (a missing
            // table mid-deploy, a lock timeout) would take every later statement of that
            // sign-in down with it — the fallback below would answer, and the next query
            // would not. Rolling back to the savepoint keeps the caller's transaction usable.
            [$policy, $collected] = DB::transaction(function () use ($request, $flow, $method, $email, $assessment): array {
                $policy = $this->policy->snapshot();

                return [$policy, $this->signals->collect($request, $flow, $method, $email, $assessment, $policy)];
            });

            return new RadarDecision(
                $assessment,
                RadarEngine::evaluate($collected['facts'], $policy),
                $policy->mode,
                $collected['facts'],
            );
        } catch (Throwable $e) {
            Log::warning('radar could not evaluate an attempt; falling back to the risk score', [
                'error' => $e->getMessage(),
            ]);

            return self::fromScoreAlone($assessment);
        }
    }

    /** A failed sign-in, for the failure counters. */
    public function failed(Request $request, ?string $email): void
    {
        $this->signals->recordFailure($request, $email);
    }

    /**
     * A sign-in SUCCEEDED for this subject: remember the device and where it was.
     *
     * Called from the one place every door establishes a session, so a password, a passkey,
     * a magic link, single sign-on and a completed step-up all count. Fails open.
     */
    public function succeeded(Request $request, string $subjectId): void
    {
        try {
            $email = $this->subjects->find($subjectId)?->email;

            if (! is_string($email) || $email === '') {
                return;
            }

            $ip = $request->ip();
            $profile = is_string($ip) ? $this->intelligence->lookup($ip) : null;

            // In a savepoint, for the same reason as assess().
            DB::transaction(fn () => $this->devices->remember($this->pseudonyms->subject($email), $this->devices->identify($request), $profile));
        } catch (Throwable $e) {
            Log::warning('radar could not remember a successful sign-in', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The verdict the risk score alone gives, under the deployment's mode — the behaviour of
     * the two `risk_score_*` built-in rules at their defaults, and of the app before Radar.
     */
    public static function fromScoreAlone(RiskAssessment $assessment): RadarDecision
    {
        $verdict = match ($assessment->outcome) {
            Outcome::Reject => new RadarVerdict(RadarAction::Block, 'builtin:risk_score_reject', 'Risk score: reject', ['builtin:risk_score_reject'], ['Risk score reached the reject threshold.']),
            Outcome::Challenge, Outcome::StepUp => new RadarVerdict(RadarAction::Challenge, 'builtin:risk_score_elevated', 'Risk score: elevated', ['builtin:risk_score_elevated'], ['Risk score reached the challenge threshold.']),
            default => RadarVerdict::allow(),
        };

        return new RadarDecision($assessment, $verdict, RadarPolicy::deploymentMode());
    }
}
