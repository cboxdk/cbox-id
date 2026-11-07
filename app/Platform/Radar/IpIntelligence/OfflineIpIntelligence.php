<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

/** Local databases or already cached evidence; never initiate an external lookup. */
interface OfflineIpIntelligence
{
    public function lookupOffline(string $ip): ?IpProfile;
}
