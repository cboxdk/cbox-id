<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

/**
 * An organization's progress through {@see SetupStepKey}, as a value: the steps it
 * can actually perform, in order, each already measured.
 */
final readonly class SetupProgress
{
    /**
     * @param  list<SetupStep>  $steps  Only the steps this organization can perform.
     */
    public function __construct(
        public array $steps,
    ) {}

    /** @return list<SetupStep> */
    public function done(): array
    {
        return array_values(array_filter($this->steps, fn (SetupStep $s): bool => $s->done));
    }

    /** @return list<SetupStep> */
    public function remaining(): array
    {
        return array_values(array_filter($this->steps, fn (SetupStep $s): bool => ! $s->done));
    }

    public function completed(): int
    {
        return count($this->done());
    }

    public function total(): int
    {
        return count($this->steps);
    }

    public function isComplete(): bool
    {
        return $this->remaining() === [];
    }

    /** 0–100, for the progress bar. An empty checklist is a complete one. */
    public function percent(): int
    {
        if ($this->total() === 0) {
            return 100;
        }

        return (int) round($this->completed() / $this->total() * 100);
    }

    /** The step to nudge towards: the first one not yet done. */
    public function next(): ?SetupStep
    {
        return $this->remaining()[0] ?? null;
    }
}
