<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Enums\PlatformOccupant;

/**
 * What an installer found when it asked whether this platform is still empty.
 *
 * Emptiness is answered ONCE, here, because two answers would eventually disagree — and
 * the two consumers are the install command (which refuses a non-empty platform) and the
 * first-run screen (which must stop existing the moment the platform is claimed). If
 * those diverged, the screen that provisions the platform root would outlive the
 * platform it provisioned.
 */
final readonly class PlatformOccupancy
{
    /** @param list<PlatformOccupant> $occupants */
    private function __construct(public array $occupants) {}

    public static function of(PlatformOccupant ...$occupants): self
    {
        return new self(array_values($occupants));
    }

    public function isEmpty(): bool
    {
        return $this->occupants === [];
    }

    /**
     * Every reason the platform is considered occupied, in one sentence.
     *
     * "Already installed" tells an operator nothing they can act on. Naming what was
     * found is what lets them see whether they are looking at a live deployment or at a
     * half-finished one they meant to wipe.
     */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'the platform is empty';
        }

        return implode(', ', array_map(
            static fn (PlatformOccupant $occupant): string => $occupant->found(),
            $this->occupants,
        ));
    }
}
