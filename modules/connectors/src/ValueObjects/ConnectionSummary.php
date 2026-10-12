<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\ValueObjects;

use Carbon\CarbonInterface;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;

/**
 * One live connection in the unified list, normalised across every connector type to a
 * common shape. It carries no secret and no raw model — only the identity, the type, a human
 * name, the lifecycle status, where it points (a URL or a protocol, never a credential), who
 * it belongs to, and what its own records say about how it is doing.
 *
 * TWO SOURCES OF HEALTH, and the record wins only where nothing better is wired:
 *
 *  - `signal` is read from the row itself — a webhook's consecutive failures, a directory's
 *    last sync result, a log stream's circuit. Every installation has it.
 *  - `health` is what a {@see ConnectorAnalytics} backend
 *    reports over a delivery window, when the host binds one.
 */
readonly class ConnectionSummary
{
    public const string HEALTHY = 'healthy';

    public const string FAILING = 'failing';

    public const string IDLE = 'idle';

    public function __construct(
        public ConnectorCategory $category,
        public string $id,
        public string $name,
        /** Lifecycle status as the module reports it: `active`, `paused`, `draft`, `disabled`… */
        public string $status,
        /** Where it points: base URL, subscriber URL, protocol or provider; null if not applicable. */
        public ?string $target,
        public ?ConnectorHealth $health = null,
        /** The organization it belongs to; null is the whole environment's. */
        public ?string $organizationId = null,
        /** When it last did something that worked or failed, as far as its row records. */
        public ?CarbonInterface $lastActivityAt = null,
        /** {@see self::HEALTHY}, {@see self::FAILING}, {@see self::IDLE}, or null where the row records nothing. */
        public ?string $signal = null,
        /** The last error the row recorded, for the list to say WHY it is failing. */
        public ?string $problem = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The verdict the list shows: an analytics backend's when one is bound (its "degraded"
     * is this list's "failing"), else the row's own signal.
     */
    public function verdict(): ?string
    {
        $reported = $this->health?->verdict();

        return match ($reported) {
            'degraded' => self::FAILING,
            null => $this->signal,
            default => $reported,
        };
    }

    /** Whether somebody should look at it: it is failing, whatever its status. */
    public function needsAttention(): bool
    {
        return $this->verdict() === self::FAILING;
    }

    public function withHealth(ConnectorHealth $health): self
    {
        return new self(
            category: $this->category,
            id: $this->id,
            name: $this->name,
            status: $this->status,
            target: $this->target,
            health: $health,
            organizationId: $this->organizationId,
            lastActivityAt: $health->lastActivityAt ?? $this->lastActivityAt,
            signal: $this->signal,
            problem: $this->problem,
        );
    }
}
