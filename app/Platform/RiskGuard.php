<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\RiskDecision;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Http\Request;

/**
 * Thin app-layer bridge to the risk scorer for our Livewire auth flows (login,
 * signup) — where the risk:<action> middleware doesn't reach, since those are
 * component actions, not plain POST routes. Scores the request, records the
 * decision for observability, and tells the caller whether to hard-block.
 */
final class RiskGuard
{
    public function __construct(
        private readonly RiskScorer $scorer,
        private readonly RiskTrail $trail,
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
     * @param  array<string, mixed>  $attributes  extra signals (honeypot, form timing)
     */
    public function assess(Request $request, string $action, ?string $email = null, array $attributes = []): RiskAssessment
    {
        $assessment = $this->scorer->assess(new RiskContext(
            action: $action,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            email: $email,
            headers: $this->headers($request),
            attributes: $attributes,
        ));

        // Persist the decision with its reasons (IP and email HMAC-pseudonymised — see
        // the risk package's GDPR guidance). One durable {@see RiskDecision} row per
        // assessment: this is what a threshold is actually set from, and it is why the
        // previous `Log::info` is gone — on `LOG_CHANNEL=stderr` with no aggregation
        // that line survived exactly until the next pod rollout.
        $this->trail->record($action, $request->ip(), $email, $assessment);

        return $assessment;
    }

    /**
     * Hard-block only a Reject, and only when enforcement is on.
     */
    public function shouldBlock(RiskAssessment $assessment): bool
    {
        return $this->enforcing() && $assessment->outcome === Outcome::Reject;
    }

    /**
     * Demand an additional factor (step-up) for an elevated-but-not-reject outcome
     * (Challenge / StepUp), when enforcement is on. The login flow turns this into a
     * second factor before the session is established. Below this — Flag / Allow — the
     * attempt proceeds with only the friction of being logged.
     */
    public function shouldStepUp(RiskAssessment $assessment): bool
    {
        return $this->enforcing()
            && in_array($assessment->outcome, [Outcome::Challenge, Outcome::StepUp], true);
    }

    private function enforcing(): bool
    {
        return config('risk.mode') === 'enforce';
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
