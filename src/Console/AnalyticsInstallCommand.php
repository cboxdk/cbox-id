<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Console;

use Cbox\Id\Analytics\ClickHouse\ClickHouseConnection;
use Illuminate\Console\Command;

/**
 * Creates the ClickHouse event table (a ReplacingMergeTree keyed on `event_id`, so
 * at-least-once outbox re-delivery collapses). There is deliberately no Laravel
 * migration for this — ClickHouse is not a Laravel connection — so the DDL ships as
 * `resources/clickhouse/schema.sql` and this command applies it over the HTTP
 * interface, or prints it for an operator to run by hand.
 */
final class AnalyticsInstallCommand extends Command
{
    protected $signature = 'id-analytics:install {--print : Print the ClickHouse DDL instead of executing it}';

    protected $description = 'Install the ClickHouse analytics schema for Cbox ID.';

    public function handle(): int
    {
        $schema = $this->schema();
        $dsn = $this->stringConfig('id-analytics.clickhouse.dsn', '');

        if ((bool) $this->option('print') || $dsn === '') {
            if ($dsn === '') {
                $this->warn('No ClickHouse DSN configured (id-analytics.clickhouse.dsn) — printing DDL only.');
            }

            $this->line($schema);

            return self::SUCCESS;
        }

        $connection = $this->laravel->make(ClickHouseConnection::class);
        $connection->execute($schema);

        $this->info('ClickHouse analytics schema installed.');

        return self::SUCCESS;
    }

    private function schema(): string
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 2).'/resources/clickhouse/schema.sql');
        $table = $this->stringConfig('id-analytics.clickhouse.table', 'id_analytics_events');

        if ($table !== '' && $table !== 'id_analytics_events') {
            $schema = str_replace('id_analytics_events', $table, $schema);
        }

        return $schema;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
