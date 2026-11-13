<?php

declare(strict_types=1);

namespace App\Platform\Dns;

use Cbox\Dns\Contracts\Resolver;
use Cbox\Dns\Enums\RecordType;
use Cbox\Dns\Exceptions\ResolutionFailed;
use Cbox\Dns\ValueObjects\DnsResponse;
use Closure;

/**
 * ONE DEADLINE FOR A WHOLE LOOKUP, not one per query.
 *
 * An authoritative lookup resolves the zone's nameservers and then asks each of them in
 * turn until one answers. The zone's owner chooses those nameservers: 20 names with 8
 * addresses each that silently drop DNS made one domain verification try 160 servers one
 * after another, ~6 s each, inside a web request. Each query keeps its own short timeout;
 * this stops asking once the lookup as a whole has had its time, and the caller reads that
 * as "nothing answered" (fail closed: not verified yet).
 *
 * {@see start()} begins a lookup's budget. A query in flight when the budget runs out is
 * not interrupted, so the worst case is the budget plus one query's timeout.
 */
final class DeadlineResolver implements Resolver
{
    private ?float $deadline = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  Seconds, monotonic. Tests pass their own.
     */
    public function __construct(
        private readonly Resolver $inner,
        private readonly float $budgetSeconds,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function start(): void
    {
        $this->deadline = ($this->clock)() + $this->budgetSeconds;
    }

    public function query(string $host, RecordType $type, ?string $nameserver = null, bool $recursion = true, bool $dnssec = false): DnsResponse
    {
        if ($this->deadline !== null && ($this->clock)() >= $this->deadline) {
            throw ResolutionFailed::make($nameserver ?? 'resolver', 'the lookup ran out of time');
        }

        return $this->inner->query($host, $type, $nameserver, $recursion, $dnssec);
    }
}
