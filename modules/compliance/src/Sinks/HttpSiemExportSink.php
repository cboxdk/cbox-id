<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Sinks;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Illuminate\Support\Facades\Http;

/**
 * POSTs each batch as a JSON document to a configured SIEM ingest endpoint, through the
 * platform's SSRF guard. An optional bearer token authenticates the request.
 *
 * The docblock used to CLAIM the guard while the code used a bare HTTP factory — no DNS
 * pinning, and redirects followed, which Guzzle does by default. The endpoint is operator
 * configuration rather than tenant input, so this was never remotely triggerable; but a
 * 302 from that endpoint, or from anything in front of it, would have sent the audit
 * batch AND its bearer token to whatever the redirect named. A false comment is how a
 * theoretical gap becomes a real one later. The endpoint is expected to be idempotent on
 * `{scope, from_sequence, to_sequence}` so an at-least-once re-offer collapses.
 *
 * A non-2xx response (or a transport error) throws — the engine then holds its
 * cursor and re-offers the batch on the next run, so a SIEM outage degrades to
 * "export lag", never to dropped entries.
 */
class HttpSiemExportSink implements AuditExportSink
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $token = '',
        private readonly int $timeout = 10,
    ) {}

    public function export(AuditExportBatch $batch): void
    {
        if ($batch->records === []) {
            return;
        }

        // Http::ssrf() resolves and pins the address, refuses private and metadata
        // ranges, and re-checks the peer after connect. withoutRedirecting() is the other
        // half: a pinned first hop means nothing if a 30x can walk the request somewhere
        // else afterwards — the webhook dispatcher takes the same pair for the same
        // reason.
        $request = Http::ssrf($this->endpoint)
            ->asJson()
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout(max(1, $this->timeout));

        if ($this->token !== '') {
            $request = $request->withToken($this->token);
        }

        $request->post($this->endpoint, $batch->toArray())->throw();
    }

    /** A real destination. */
    public function isInert(): bool
    {
        return false;
    }
}
