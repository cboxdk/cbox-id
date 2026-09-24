<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use App\Platform\Queues\Contracts\ManagerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * The heartbeat, kept in the application cache.
 *
 * The default store on purpose: the manager and the web tier are different processes —
 * on some deployments different machines — and the default store is the one both are
 * configured with. An `array` store would make every process see only its own beats,
 * which is the test suite's shape and nothing else's.
 *
 * Kept for a day, far longer than any staleness window. Freshness is judged from the
 * timestamp INSIDE the value, not from whether the key exists, so "the manager stopped
 * at 09:14" stays readable instead of dissolving into "never ran".
 */
class CacheManagerHeartbeat implements ManagerHeartbeat
{
    public const KEY = 'cbox-id:queue-manager:heartbeat';

    private const RETAIN_SECONDS = 86_400;

    public function __construct(private readonly Repository $cache) {}

    public function beat(string $managerId, string $host): void
    {
        $this->cache->put(self::KEY, (new ManagerBeat($managerId, $host, CarbonImmutable::now()))->toArray(), self::RETAIN_SECONDS);
    }

    public function last(): ?ManagerBeat
    {
        return ManagerBeat::fromStored($this->cache->get(self::KEY));
    }
}
