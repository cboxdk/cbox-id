<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

/**
 * What is known about a client address: where it is, whose network it is on, and whether
 * that network is the kind people hide behind.
 *
 * Pluggable because every source carries its own licence and cost — a MaxMind database file
 * the operator downloads ({@see MaxMindIpIntelligence}), the IPinfo API with the operator's
 * token ({@see IpinfoIpIntelligence}), or nothing at all ({@see NullIpIntelligence}, the
 * default). Bind your own to use another.
 *
 * IMPLEMENTATIONS MUST FAIL OPEN: answer null — never throw — when the address cannot be
 * looked up, so an outage or a missing file degrades to "unknown", which no rule matches.
 * A lookup must never be what blocks a sign-in.
 */
interface IpIntelligence
{
    public function lookup(string $ip): ?IpProfile;
}
