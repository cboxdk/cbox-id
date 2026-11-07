<?php

declare(strict_types=1);

namespace Cbox\Signals;

use Cbox\Signals\Contracts\CountIntervals;
use Cbox\Signals\Data\IntervalHistoryQuery;
use Cbox\Signals\Data\IntervalPage;
use Cbox\Signals\Data\IntervalReceipt;
use Cbox\Signals\Data\IntervalSeries;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Support\Wire;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** No automatic retry or zero-filling. Callers persist exact revisions before sending. */
class HttpCountIntervals implements CountIntervals
{
    public function __construct(private readonly Factory $http, private readonly string $url, private readonly string $token, private readonly float $timeout = 2) {}

    public function ingest(IntervalSeries $series, array $records): IntervalReceipt
    {
        if (count($records) < 1 || count($records) > 1000) {
            throw new InvalidArgumentException('An interval batch contains 1–1000 records.');
        }
        $wire = [];
        foreach ($records as $record) {
            if ($record->series != $series) {
                throw new InvalidArgumentException('Every interval must belong to the requested series.');
            }
            $item = $record->toArray();
            if (strlen(json_encode($item, JSON_THROW_ON_ERROR)) > 16384) {
                throw new InvalidArgumentException('Interval record exceeds 16 KiB.');
            }
            $wire[] = $item;
        }
        $body = json_encode(['records' => $wire], JSON_THROW_ON_ERROR);
        if (strlen($body) > 1048576) {
            throw new InvalidArgumentException('Interval batch exceeds one MiB.');
        }
        $receipt = IntervalReceipt::fromArray($this->request('POST', $series->path().':batch', $body));
        if (count($receipt->records) !== count($records)) {
            Wire::fail();
        }
        foreach ($receipt->records as $index => $ack) {
            if ($ack->revision !== $records[$index]->revision) {
                Wire::fail();
            }
        }

        return $receipt;
    }

    public function history(IntervalSeries $series, IntervalHistoryQuery $query): IntervalPage
    {
        try {
            $page = IntervalPage::fromArray($this->request('GET', $series->path(), null, $query->toArray()));
        } catch (InvalidArgumentException) {
            Wire::fail();
        }
        if (count($page->data) > $query->limit || ($query->asOf !== null && $page->asOf != $query->asOf)) {
            Wire::fail();
        }
        foreach ($page->data as $item) {
            if ($item->record->series != $series || Wire::date($item->record->start) < $query->from || Wire::date($item->record->end) > $query->through) {
                Wire::fail();
            }
        }

        return $page;
    }

    /** @param array<string,int|string> $query
     * @return array<string,mixed> */
    private function request(string $method, string $path, ?string $body = null, array $query = []): array
    {
        if ($this->url === '' || $this->token === '') {
            throw new SignalsFailure('not_configured', 503);
        }
        try {
            $request = $this->http->withToken($this->token)->acceptJson()->timeout($this->timeout)->connectTimeout(min($this->timeout, 1))->withoutRedirecting()->withOptions(['stream' => true]);
            $response = $method === 'GET' ? $request->get(rtrim($this->url, '/').$path, $query) : $request->withBody($body ?? '', 'application/json')->post(rtrim($this->url, '/').$path);
        } catch (ConnectionException) {
            throw new SignalsFailure('unavailable', 503);
        }
        // 1,000 records of at most 16 KiB plus bounded provenance envelopes.
        // Refuse oversized error bodies too; never buffer an unbounded reply.
        $limit = $response->status() === 200 ? 17 * 1024 * 1024 : 65536;
        $stream = $response->toPsrResponse()->getBody();
        try {
            $raw = Utils::copyToString($stream, $limit + 1);
            if (strlen($raw) > $limit) {
                Wire::fail();
            }
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (RuntimeException|JsonException) {
            Wire::fail();
        } finally {
            $stream->close();
        }
        if ($response->status() !== 200) {
            $code = is_array($data) ? ($data['code'] ?? null) : null;
            $known = ['invalid_credential', 'missing_scope', 'forbidden', 'request_rate_limited', 'invalid_count_interval', 'interval_scope_denied', 'interval_history_retired', 'invalid_interval_query', 'invalid_interval_revision', 'subject_erased', 'project_erased', 'idempotency_conflict', 'quota_exhausted', 'project_backpressure', 'invalid_json', 'request_too_large', 'unavailable'];
            throw new SignalsFailure(is_string($code) && in_array($code, $known, true) ? $code : 'unavailable', $response->status());
        }

        return Wire::object($data);
    }
}
