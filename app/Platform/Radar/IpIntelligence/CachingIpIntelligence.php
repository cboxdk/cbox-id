<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

use App\Platform\Radar\RadarPseudonyms;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Remembers what a source said about an address, so a credential-stuffing burst from one
 * address costs one lookup and not one per attempt.
 *
 * Keyed by a PSEUDONYM of the address, never the address. "Unknown" is remembered too (for a
 * shorter time), so an address the source cannot place does not turn every attempt from it
 * into a fresh API call. Private, loopback and reserved addresses are never looked up at all:
 * no source knows anything about them, and asking would only send them somewhere.
 */
final readonly class CachingIpIntelligence implements IpIntelligence
{
    private const string UNKNOWN = 'unknown';

    public function __construct(
        private IpIntelligence $inner,
        private Cache $cache,
        private RadarPseudonyms $pseudonyms,
        private int $ttlSeconds = 86400,
    ) {}

    public function lookup(string $ip): ?IpProfile
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $key = 'radar:ip:'.$this->pseudonyms->ip($ip);
        $cached = $this->cache->get($key);

        if ($cached === self::UNKNOWN) {
            return null;
        }

        if (is_array($cached)) {
            return IpProfile::fromArray($cached);
        }

        $profile = $this->inner->lookup($ip);

        $profile === null
            ? $this->cache->put($key, self::UNKNOWN, min($this->ttlSeconds, 3600))
            : $this->cache->put($key, $profile->toArray(), $this->ttlSeconds);

        return $profile;
    }
}
