<?php

declare(strict_types=1);

namespace App\Platform\Install\Exceptions;

use App\Platform\Install\PlatformOccupancy;
use RuntimeException;

/**
 * Refusal to bootstrap a platform that is already in use.
 *
 * Carries the occupancy rather than a bare message so every caller reports the same
 * finding — the CLI prints it, the first-run screen 404s on it — and neither has to
 * re-derive why the platform was considered claimed.
 */
final class PlatformNotEmpty extends RuntimeException
{
    private function __construct(public readonly PlatformOccupancy $occupancy, string $message)
    {
        parent::__construct($message);
    }

    public static function with(PlatformOccupancy $occupancy): self
    {
        return new self(
            $occupancy,
            'This platform is already installed — '.$occupancy->describe().'.',
        );
    }
}
