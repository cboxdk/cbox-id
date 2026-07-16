<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Sinks;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * POSTs each batch as a JSON document to a configured SIEM ingest endpoint via the
 * platform's HTTP client (SSRF-guarded in the host). An optional bearer token
 * authenticates the request. The endpoint is expected to be idempotent on
 * `{scope, from_sequence, to_sequence}` so an at-least-once re-offer collapses.
 *
 * A non-2xx response (or a transport error) throws — the engine then holds its
 * cursor and re-offers the batch on the next run, so a SIEM outage degrades to
 * "export lag", never to dropped entries.
 */
final class HttpSiemExportSink implements AuditExportSink
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $endpoint,
        private readonly string $token = '',
        private readonly int $timeout = 10,
    ) {}

    public function export(AuditExportBatch $batch): void
    {
        if ($batch->records === []) {
            return;
        }

        $request = $this->http
            ->asJson()
            ->acceptJson()
            ->timeout(max(1, $this->timeout));

        if ($this->token !== '') {
            $request = $request->withToken($this->token);
        }

        $request->post($this->endpoint, $batch->toArray())->throw();
    }
}
