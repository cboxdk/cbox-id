<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thin app-layer bridge to the risk scorer for our Livewire auth flows (login,
 * signup) — where the risk:<action> middleware doesn't reach, since those are
 * component actions, not plain POST routes. Scores the request, records the
 * decision for observability, and tells the caller whether to hard-block.
 */
final class RiskGuard
{
    public function __construct(private readonly RiskScorer $scorer) {}

    /**
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

        // Log every decision with its reasons (IP hashed — see the risk package's
        // GDPR guidance). This is the audit trail for tuning and review.
        $appKey = config('app.key');

        Log::info('auth risk assessed', [
            'action' => $action,
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), is_string($appKey) ? $appKey : ''),
            'score' => $assessment->score,
            'outcome' => $assessment->outcome->value,
            'reasons' => $assessment->reasons(),
        ]);

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
