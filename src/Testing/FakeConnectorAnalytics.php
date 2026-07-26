<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Testing;

use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorHealth;

/**
 * An in-memory {@see ConnectorAnalytics} for tests and local demos: pre-seed health
 * per connection and read it back, no delivery-log backend required. Ships with the
 * package so a host (and this package's own suite) can assert the wired-up path
 * without ClickHouse.
 */
class FakeConnectorAnalytics implements ConnectorAnalytics
{
    /** @var array<string, ConnectorHealth> */
    private array $health = [];

    public function set(ConnectorCategory $category, string $connectionId, ConnectorHealth $health): self
    {
        $this->health[$this->key($category, $connectionId)] = $health;

        return $this;
    }

    public function health(ConnectorCategory $category, string $connectionId): ?ConnectorHealth
    {
        return $this->health[$this->key($category, $connectionId)] ?? null;
    }

    private function key(ConnectorCategory $category, string $connectionId): string
    {
        return $category->value.':'.$connectionId;
    }
}
