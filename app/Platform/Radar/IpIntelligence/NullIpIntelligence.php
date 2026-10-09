<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

/**
 * The default: nothing is known about any address. Country, network and travel rules never
 * fire, and nothing leaves the deployment. Choose a driver to switch them on.
 */
final readonly class NullIpIntelligence implements IpIntelligence
{
    public function lookup(string $ip): ?IpProfile
    {
        return null;
    }
}
