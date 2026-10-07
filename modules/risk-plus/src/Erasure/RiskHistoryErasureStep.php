<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Erasure;

use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The person's risk history: the elevated sign-ins on the review trail, and what the
 * adaptive signals remember about where and on what they sign in.
 *
 * THE REVIEW TRAIL holds the address and the IP in the clear — it is a console for a
 * person to read — so its rows for the address are deleted. Matched case-insensitively,
 * because the trail stores the address as it was typed at the sign-in form.
 *
 * THE SIGNALS' MEMORY lives in the cache, under an HMAC of the environment and the address
 * ({@see SubjectKey}): the last location for impossible travel, the device fingerprints for
 * new-device. Both are forgotten. A cache is not part of the eraser's transaction, so a
 * rolled-back erasure has still forgotten them — which costs the person one "new device"
 * score on their next sign-in, and is the right way round to fail.
 */
final readonly class RiskHistoryErasureStep implements ErasureStep
{
    public function __construct(
        private SubjectKey $subjects,
        private Cache $cache,
    ) {}

    public function name(): string
    {
        return 'risk_plus.history';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $email = $request->email === null ? '' : strtolower(trim($request->email));

        if ($email === '') {
            return ErasureStepResult::of($this->name(), ['risk_events' => 0]);
        }

        $deleted = RiskEvent::query()->whereRaw('LOWER(email) = ?', [$email])->toBase()->delete();

        $key = $this->subjects->for(new RiskContext('erasure', email: $email));

        if ($key !== null) {
            $this->cache->forget('cbox-riskplus:devices:'.$key);
            $this->cache->forget('cbox-riskplus:geo:'.$key);
        }

        return ErasureStepResult::of($this->name(), ['risk_events' => $deleted]);
    }
}
