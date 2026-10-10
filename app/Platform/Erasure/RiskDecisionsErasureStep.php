<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Models\RiskDecision;
use App\Platform\RiskTrail;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * The person's address on the risk trail, unlinked.
 *
 * The trail never held the address itself — only a keyed pseudonym of it
 * ({@see RiskTrail::emailPseudonym()}) — but a keyed pseudonym is still personal data: it
 * exists precisely so an operator who knows the address can find every attempt made with
 * it. So the pseudonym is removed and the DECISION kept. The row is then a scored attempt
 * from a mail domain at a time, which is what thresholds are tuned on and identifies
 * nobody.
 *
 * Scoped to THIS environment: the table is deliberately unscoped (tuning is a
 * deployment-wide question), and the same address in another environment is another
 * person's account.
 */
final readonly class RiskDecisionsErasureStep implements ErasureStep
{
    public function __construct(
        private RiskTrail $trail,
        private EnvironmentContext $environments,
    ) {}

    public function name(): string
    {
        return 'app.risk_decisions';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        if ($request->email === null || trim($request->email) === '') {
            return ErasureStepResult::of($this->name(), ['risk_decisions' => 0]);
        }

        $unlinked = RiskDecision::query()
            ->where('environment_id', $this->environments->current()?->environmentKey())
            ->where('email_hash', $this->trail->emailPseudonym($request->email))
            // The device pseudonym too: with the address gone it is the one thing left that
            // would tie these attempts back to their browser.
            ->update(['email_hash' => null, 'device_hash' => null]);

        return ErasureStepResult::of($this->name(), ['risk_decisions' => $unlinked]);
    }
}
