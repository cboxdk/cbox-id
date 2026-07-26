<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Analytics;

use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorHealth;

/**
 * The default analytics backend: it knows nothing. With it bound the plugin reports
 * no delivery health, so the unified connections view still renders (status from the
 * module contracts) but shows no health badge. Installing without a delivery-log
 * backend is therefore safe and free — a real {@see ConnectorAnalytics} can be bound
 * by the SaaS host to light the badges up.
 */
class NullConnectorAnalytics implements ConnectorAnalytics
{
    public function health(ConnectorCategory $category, string $connectionId): ?ConnectorHealth
    {
        return null;
    }
}
