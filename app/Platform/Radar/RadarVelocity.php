<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Sliding-window counters in the shared cache — how many attempts from this IP in the last
 * minute, how many different addresses from it in the last ten.
 *
 * Each window is two fixed buckets, the current and the previous, and the count is the
 * current bucket plus the share of the previous one still inside the window: the standard
 * sliding-window approximation. It never undercounts a burst that straddles a bucket
 * boundary the way a single fixed bucket does, and it costs two cache reads.
 *
 * Every key is a pseudonym under the environment ({@see RadarPseudonyms::counter()}): no IP
 * or address is a cache key, and one tenant's traffic never moves another's counters.
 *
 * Counts are only as shared as the cache store: a per-process store counts per replica.
 * `cbox-id.radar.cache_store` names a shared one.
 */
final readonly class RadarVelocity
{
    /** The most distinct members a set bucket remembers — the count saturates there. */
    private const int MAX_MEMBERS = 1000;

    public function __construct(
        private Cache $cache,
        private RadarPseudonyms $pseudonyms,
    ) {}

    /** Count one event against `$kind:$value` in a window of `$seconds`. */
    public function hit(string $kind, string $value, int $seconds): void
    {
        $key = $this->key($kind, $value, $seconds, $this->bucket($seconds));

        $this->cache->add($key, 0, $seconds * 2);
        $this->cache->increment($key);
    }

    /** Events against `$kind:$value` within the last `$seconds`. */
    public function count(string $kind, string $value, int $seconds): int
    {
        $bucket = $this->bucket($seconds);
        $current = $this->int($this->cache->get($this->key($kind, $value, $seconds, $bucket)));
        $previous = $this->int($this->cache->get($this->key($kind, $value, $seconds, $bucket - 1)));

        return $current + (int) ceil($previous * $this->previousWeight($seconds));
    }

    /** Remember `$member` (already a pseudonym) in the set `$kind:$value` for a window. */
    public function remember(string $kind, string $value, string $member, int $seconds): void
    {
        $key = $this->key($kind, $value, $seconds, $this->bucket($seconds));
        $members = $this->members($this->cache->get($key));

        if (isset($members[$member]) || count($members) >= self::MAX_MEMBERS) {
            return;
        }

        $members[$member] = true;
        $this->cache->put($key, array_keys($members), $seconds * 2);
    }

    /** How many different members the set `$kind:$value` saw within the last `$seconds`. */
    public function distinct(string $kind, string $value, int $seconds): int
    {
        $bucket = $this->bucket($seconds);
        $current = $this->members($this->cache->get($this->key($kind, $value, $seconds, $bucket)));
        $previous = array_diff_key($this->members($this->cache->get($this->key($kind, $value, $seconds, $bucket - 1))), $current);

        return count($current) + (int) ceil(count($previous) * $this->previousWeight($seconds));
    }

    private function bucket(int $seconds): int
    {
        return intdiv(Carbon::now()->getTimestamp(), max(1, $seconds));
    }

    /** The share of the previous bucket that still lies inside the window. */
    private function previousWeight(int $seconds): float
    {
        $seconds = max(1, $seconds);

        return 1 - ((Carbon::now()->getTimestamp() % $seconds) / $seconds);
    }

    private function key(string $kind, string $value, int $seconds, int $bucket): string
    {
        return 'radar:v:'.$this->pseudonyms->counter($kind, $value).':'.$seconds.':'.$bucket;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, true>
     */
    private function members(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $members = [];

        foreach ($stored as $member) {
            if (is_string($member)) {
                $members[$member] = true;
            }
        }

        return $members;
    }
}
