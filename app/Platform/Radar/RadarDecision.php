<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\RiskGuard;
use Cbox\Risk\ValueObjects\RiskAssessment;

/**
 * One assessed attempt: the risk score, Radar's verdict on it, and whether the environment's
 * mode means that verdict is acted on. What {@see RiskGuard::assess()} hands every door.
 */
final readonly class RadarDecision
{
    public function __construct(
        public RiskAssessment $assessment,
        public RadarVerdict $verdict,
        public RadarMode $mode,
        public ?RadarFacts $facts = null,
    ) {}

    public function enforced(): bool
    {
        return $this->mode === RadarMode::Enforce;
    }

    /** Refuse the attempt: a block, under enforcement. */
    public function blocks(): bool
    {
        return $this->enforced() && $this->verdict->action === RadarAction::Block;
    }

    /** Ask for proof first: a challenge, under enforcement. */
    public function challenges(): bool
    {
        return $this->enforced() && $this->verdict->action === RadarAction::Challenge;
    }
}
