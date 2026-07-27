<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Contracts;

use Cbox\Id\RiskPlus\Geo\Coordinates;

/**
 * Resolves an IP address to an approximate location.
 *
 * The impossible-travel signal needs coordinates, but the *source* is the
 * operator's to choose — a MaxMind GeoLite2 database, an IP-intelligence API, an
 * edge header (Cloudflare's `cf-iplatitude`/`cf-iplongitude`), etc. — because each
 * carries its own license and cost. The plugin ships a fail-open null locator so
 * it never guesses; wire a real locator to activate geo scoring.
 *
 * Implementations MUST fail open: return `null` (not throw) when the IP can't be
 * located, so a lookup outage degrades to "no signal", never a blocked login.
 */
interface GeoLocator
{
    public function locate(string $ip): ?Coordinates;
}
