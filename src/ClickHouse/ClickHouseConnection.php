<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\ClickHouse;

use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use RuntimeException;

/**
 * A thin wrapper over ClickHouse's HTTP interface, built on Laravel's HTTP client —
 * no hand-rolled native protocol. This is the ONLY class in the plugin that talks to
 * ClickHouse; everything else speaks the {@see ReportSink}
 * / {@see ReportReader} contracts, keeping the open
 * framework column-store-free.
 *
 * Server-side query parameters (`{name:Type}` + `param_name=`) are used for every
 * value so untrusted input can never be interpolated into SQL.
 */
final class ClickHouseConnection
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $dsn,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly int $timeout = 5,
    ) {}

    /**
     * Batch-insert rows in JSONEachRow format.
     *
     * @param  list<array<string, scalar>>  $rows
     */
    public function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = json_encode($row, JSON_THROW_ON_ERROR);
        }

        $sql = sprintf('INSERT INTO %s.%s FORMAT JSONEachRow', $this->database, $table);

        $this->send($sql, implode("\n", $lines), []);
    }

    /**
     * Run a read query, returning decoded rows. `$parameters` are bound server-side
     * as `param_<name>` and referenced in SQL as `{<name>:Type}`.
     *
     * @param  array<string, scalar>  $parameters
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $body = $this->send($sql.' FORMAT JSONEachRow', null, $parameters);

        $rows = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * Execute a DDL / statement with no result body (schema install, TTL changes).
     */
    public function execute(string $sql): void
    {
        $this->send($sql, null, []);
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function send(string $sql, ?string $body, array $parameters): string
    {
        $query = ['query' => $sql, 'user' => $this->user, 'password' => $this->password];

        foreach ($parameters as $name => $value) {
            $query['param_'.$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        $request = $this->http->timeout($this->timeout)->withQueryParameters($query);

        if ($body !== null) {
            $request = $request->withBody($body, 'text/plain');
        }

        $response = $request->post($this->endpoint());

        if ($response->failed()) {
            throw new RuntimeException('ClickHouse request failed: '.$response->status());
        }

        return $response->body();
    }

    private function endpoint(): string
    {
        return rtrim($this->dsn, '/');
    }
}
