<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\ValueObjects;

use Cbox\Id\Connectors\Enums\ConnectorCategory;

/**
 * One live connection in the unified per-organization view, normalised across the
 * four connector modules to a common shape. It carries no secret and no raw model —
 * only the identity, category, human name, lifecycle status, the target it talks to
 * (a URL or domain, never a credential), and optional delivery {@see ConnectorHealth}
 * once an analytics backend is wired.
 */
readonly class ConnectionSummary
{
    public function __construct(
        public ConnectorCategory $category,
        public string $id,
        public string $name,
        /** Lifecycle status as the module reports it: e.g. `active`, `paused`. */
        public string $status,
        /** Where it points: base URL, subscriber URL, or verified domain; null if not applicable. */
        public ?string $target,
        public ?ConnectorHealth $health = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
