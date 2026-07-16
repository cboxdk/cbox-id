<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Listeners;

use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\Events\RiskAssessed;
use Throwable;

/**
 * Persists elevated assessments (Flag or worse) as {@see RiskEvent}s for the
 * console to review. It fails open — a write error is swallowed, never propagated
 * — because risk *logging* must never be what breaks a sign-in.
 */
final class RecordRiskEvent
{
    public function handle(RiskAssessed $event): void
    {
        if (! $event->assessment->atLeast(Outcome::Flag)) {
            return;
        }

        try {
            RiskEvent::query()->create([
                'action' => $event->context->action,
                'outcome' => $event->assessment->outcome->value,
                'score' => $event->assessment->score,
                'ip' => $event->context->ip,
                'email' => $event->context->email,
                'reasons' => $event->assessment->reasons(),
            ]);
        } catch (Throwable) {
            // Fail open: never let the review trail block the request it describes.
        }
    }
}
