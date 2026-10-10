<?php

declare(strict_types=1);

namespace App\Platform\Radar\Testing;

use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;

/**
 * An intelligence source that knows exactly the addresses a test tells it about, and counts
 * how often it was asked.
 */
final class FakeIpIntelligence implements IpIntelligence
{
    /** @var array<string, IpProfile> */
    private array $profiles = [];

    public int $lookups = 0;

    /**
     * @param  array<string, IpProfile>  $profiles
     */
    public function __construct(array $profiles = [])
    {
        foreach ($profiles as $ip => $profile) {
            $this->profiles[$ip] = $profile;
        }
    }

    public function place(string $ip, IpProfile $profile): self
    {
        $this->profiles[$ip] = $profile;

        return $this;
    }

    public function lookup(string $ip): ?IpProfile
    {
        $this->lookups++;

        return $this->profiles[$ip] ?? null;
    }
}
