<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Contracts;

use Cbox\Id\Connectors\Analytics\NullConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorHealth;

/**
 * The read side of connector delivery health — the queries the console decorates a
 * connection with. It is a contract so the plugin ships a {@see NullConnectorAnalytics}
 * default (inert: no backend, no health) and the SaaS host can bind a real
 * implementation (e.g. a ClickHouse delivery-log reader) with zero UI change. There
 * is no hard dependency on any column store; the framework only speaks this contract.
 *
 * All results are scoped to the current environment by the caller's context.
 */
interface ConnectorAnalytics
{
    /**
     * Recent delivery health for one connection, or null when analytics is not wired
     * or the connection has no recorded activity. `$connectionId` is the module's own
     * connection/endpoint id.
     */
    public function health(ConnectorCategory $category, string $connectionId): ?ConnectorHealth;
}
