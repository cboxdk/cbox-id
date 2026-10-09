<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\RiskDecision;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\Radar;
use App\Platform\Radar\RadarDecision;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Http\Request;

/**
 * Thin app-layer bridge to the risk scorer for our auth flows (login, signup) — where the
 * risk:<action> middleware doesn't reach. Scores the request, hands the score to
 * {@see Radar} for the verdict (allow, challenge or block, under the environment's own
 * rules and mode), records the decision for observability, and tells the caller whether to
 * hard-block or to demand a second factor.
 */
final class RiskGuard
{
    public function __construct(
        private readonly RiskScorer $scorer,
        private readonly RiskTrail $trail,
        private readonly Radar $radar,
    ) {}

    /**
     * Score the request and RECORD the decision — exactly once per assessment.
     *
     * The write lives here and nowhere else, and that placement is load-bearing.
     * {@see shouldBlock()} and {@see shouldStepUp()} are pure predicates over an
     * assessment that has already been made, and the callers that matter call BOTH on
     * the SAME assessment (login.blade.php and signup.blade.php each do). Recording
     * from either predicate — or from both — would write two rows for one sign-in
     * attempt and quietly double every count in the tuning query, which is the one
     * number the whole table exists to produce.
     *
     * `$method` is the credential the attempt is made with; a passkey and a magic link
     * prove possession on their own, so the doors that carry them only ever ask
     * {@see shouldBlock()}. Left null it is a password for `login` and a sign-up for
     * `register`.
     *
     * @param  array<string, mixed>  $attributes  extra signals (honeypot, form timing)
     */
    public function assess(Request $request, string $action, ?string $email = null, array $attributes = [], ?RadarMethod $method = null): RadarDecision
    {
        $assessment = $this->scorer->assess(new RiskContext(
            action: $action,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            email: $email,
            headers: $this->headers($request),
            attributes: $attributes,
        ));

        $flow = RadarFlow::fromRiskAction($action);
        $decision = $this->radar->assess(
            $request,
            $flow,
            $method ?? ($flow === RadarFlow::SignUp ? RadarMethod::SignUp : RadarMethod::Password),
            $email,
            $assessment,
        );

        // Persist the decision with its reasons (IP and email HMAC-pseudonymised — see
        // the risk package's GDPR guidance). One durable {@see RiskDecision} row per
        // assessment: this is what a threshold is actually set from, and it is why the
        // previous `Log::info` is gone — on `LOG_CHANNEL=stderr` with no aggregation
        // that line survived exactly until the next pod rollout.
        $this->trail->record($action, $request->ip(), $email, $assessment, $decision);

        return $decision;
    }

    /**
     * Hard-block a Radar block — by default a risk-score Reject, credential stuffing, bot
     * velocity, a disposable sign-up address, a deny-list entry or a rule that says so — and
     * only when the environment enforces.
     */
    public function shouldBlock(RadarDecision $decision): bool
    {
        return $decision->blocks();
    }

    /**
     * Demand an additional factor (step-up) for a Radar challenge — by default an elevated
     * risk score, impossible travel, an anonymising network, repeated failures — when the
     * environment enforces. The login flow turns this into a second factor before the
     * session is established. An allow proceeds with only the friction of being logged.
     */
    public function shouldStepUp(RadarDecision $decision): bool
    {
        return $decision->challenges();
    }

    /** A sign-in failed: count it, for the rules that watch failures. */
    public function failed(Request $request, ?string $email): void
    {
        $this->radar->failed($request, $email);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
