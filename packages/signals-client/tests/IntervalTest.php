<?php

use Cbox\Signals\Data\CountInterval;
use Cbox\Signals\Data\IntervalCoverage;
use Cbox\Signals\Data\IntervalEntity;
use Cbox\Signals\Data\IntervalHistoryQuery;
use Cbox\Signals\Data\IntervalScope;
use Cbox\Signals\Data\IntervalSeries;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\HttpCountIntervals;
use Cbox\Signals\Support\IntervalWire;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Opis\JsonSchema\Validator;

function intervalFixture(?string $count = '18446744073709551615', string $status = 'complete'): CountInterval
{
    return new CountInterval(new IntervalScope('project', 'production', 'customer'), new IntervalSeries('api', 'requests', 1), new IntervalEntity('api', 'token', 'internal-key-17'), '2026-11-07T10:00:00Z', '2026-11-07T11:00:00Z', '2026-11-07T11:01:00.123456789Z', $count, new IntervalCoverage($status, $status === 'unknown' ? null : '2026-11-07T11:00:00Z', $status === 'complete' ? [] : ['source_unavailable']), [new IntervalEntity('api', 'user', 'owner-17')]);
}

function intervalReceiptFixture(string $outcome = 'created'): array
{
    return ['accepted_at' => '2026-11-07T12:00:00Z', 'created' => $outcome === 'created' ? 1 : 0, 'corrected' => $outcome === 'corrected' ? 1 : 0, 'duplicates' => $outcome === 'duplicate' ? 1 : 0, 'records' => [['bucket' => str_repeat('a', 64), 'hash' => str_repeat('b', 64), 'revision' => 1, 'outcome' => $outcome]]];
}

function intervalPageFixture(): array
{
    return ['data' => [['bucket' => str_repeat('a', 64), 'hash' => str_repeat('b', 64), 'known_at' => '2026-11-07T12:00:00Z', 'record' => json_decode(json_encode(intervalFixture()->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)]], 'as_of' => '2026-11-07T12:01:00Z', 'retired_through' => '2016-11-07T12:01:00Z', 'next_cursor' => null];
}

it('preserves exact uint64 counts and source nanoseconds against the engine schema', function (): void {
    $schema = json_decode(file_get_contents(__DIR__.'/Contracts/count-interval.schema.json'), flags: JSON_THROW_ON_ERROR);
    foreach ([intervalFixture(), intervalFixture('0'), intervalFixture(null, 'unknown'), intervalFixture('17', 'partial')] as $record) {
        $encoded = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        expect((new Validator)->validate(json_decode($encoded), $schema)->isValid())->toBeTrue();
        $roundtrip = CountInterval::fromArray(json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        expect(json_encode($roundtrip->toArray(), JSON_THROW_ON_ERROR))->toBe($encoded)
            ->and($roundtrip->recordedAt)->toBe('2026-11-07T11:01:00.123456789Z');
        expect(CountInterval::fromArray($record->toArray()))->toEqual($record);
    }
    expect(IntervalWire::utc(new DateTimeImmutable('2026-11-07T12:00:00+01:00')))->toBe('2026-11-07T11:00:00.000000Z');
});

it('does not truncate nanoseconds when checking source coverage boundaries', function (): void {
    expect(IntervalWire::compare('2026-11-07T11:00:00.123456788Z', '2026-11-07T11:00:00.123456789Z'))->toBeLessThan(0)
        ->and(IntervalWire::compare('2026-11-07T11:00:00Z', '2026-11-07T11:00:00.000000000Z'))->toBe(0);
    $wire = intervalFixture()->toArray();
    $wire['end'] = '2026-11-07T11:00:00.123456789Z';
    $wire['recorded_at'] = '2026-11-07T11:00:00.123456788Z';
    expect(fn () => CountInterval::fromArray($wire))->toThrow(InvalidArgumentException::class);
    $wire['recorded_at'] = '2026-11-07T11:00:00.123456789Z';
    $wire['coverage']['observed_through'] = '2026-11-07T11:00:00.123456788Z';
    expect(fn () => CountInterval::fromArray($wire))->toThrow(InvalidArgumentException::class);
});

it('rejects overflow, ambiguous counts, raw IP entities and invented complete coverage', function (): void {
    foreach (['18446744073709551616', '-1', '1.5', '01', '1e3', ''] as $value) {
        expect(fn () => intervalFixture($value))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => intervalFixture(null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => intervalFixture('0', 'unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IntervalCoverage('complete', null, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IntervalEntity('api', 'ip', '192.0.2.17'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IntervalSeries('api', '../control', 1))->toThrow(InvalidArgumentException::class);
});

it('sends identical interval bytes on explicit retry and keeps secrets out of the body', function (): void {
    $http = new Factory;
    $http->fakeSequence()->push(intervalReceiptFixture())->push(intervalReceiptFixture('duplicate'));
    $client = new HttpCountIntervals($http, 'https://signals.test', 'private-header-only');
    $record = intervalFixture();
    expect($client->ingest($record->series, [$record])->created)->toBe(1)
        ->and($client->ingest($record->series, [$record])->duplicates)->toBe(1);
    $requests = $http->recorded();
    expect($requests[0][0]->body())->toBe($requests[1][0]->body())
        ->and($requests[0][0]->body())->not->toContain('private-header-only');
    $http->assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer private-header-only') && $r->url() === 'https://signals.test/v2/sources/api/interval-series/requests/revisions/1/intervals:batch' && $r['records'][0]['count'] === '18446744073709551615' && $r['records'][0]['scope']['tenant_id'] === 'customer');
});

it('retains history provenance and query boundaries without filling missing buckets', function (): void {
    $http = new Factory;
    $page = intervalPageFixture();
    $page['next_cursor'] = 'opaque-cursor';
    $http->fakeSequence()->push($page)->push(['data' => [], 'as_of' => $page['as_of'], 'retired_through' => $page['retired_through'], 'next_cursor' => null]);
    $client = new HttpCountIntervals($http, 'https://signals.test', 'key');
    $series = intervalFixture()->series;
    $query = new IntervalHistoryQuery(new DateTimeImmutable('2026-11-01T00:00:00Z'), new DateTimeImmutable('2026-11-07T12:00:00Z'));
    $first = $client->history($series, $query);
    expect($first->data)->toHaveCount(1)
        ->and($first->data[0]->record->count)->toBe('18446744073709551615')
        ->and($first->data[0]->knownAt->format('c'))->toBe('2026-11-07T12:00:00+00:00');
    $next = new IntervalHistoryQuery($query->from, $query->through, $first->asOf, $first->nextCursor);
    expect($client->history($series, $next)->data)->toBe([]);
    $http->assertSent(fn ($r) => $r->method() === 'GET' && ($r['cursor'] ?? null) === 'opaque-cursor' && $r['as_of'] === '2026-11-07T12:01:00.000000Z');
});

it('rejects contradictory or foreign history rather than returning usable values', function (): void {
    foreach (['count_number', 'count_missing', 'future_known_at', 'foreign_source', 'malformed_count', 'unknown_zero'] as $case) {
        $page = intervalPageFixture();
        match ($case) {
            'count_number' => $page['data'][0]['record']['count'] = 123,
            'count_missing' => $page['data'][0]['record']['count'] = null,
            'future_known_at' => $page['data'][0]['known_at'] = '2027-01-01T00:00:00Z',
            'foreign_source' => $page['data'][0]['record']['source_id'] = 'foreign',
            'malformed_count' => $page['data'][0]['record']['count'] = '1.1',
            'unknown_zero' => $page['data'][0]['record']['coverage'] = ['status' => 'unknown', 'observed_through' => null, 'reasons' => ['source_unavailable']],
        };
        $http = new Factory;
        $http->fake(['*' => Factory::response($page)]);
        $client = new HttpCountIntervals($http, 'https://signals.test', 'key');
        expect(fn () => $client->history(intervalFixture()->series, new IntervalHistoryQuery(new DateTimeImmutable('2026-11-01T00:00:00Z'), new DateTimeImmutable('2026-11-07T12:00:00Z'))))->toThrow(SignalsFailure::class);
    }
});

it('treats retired input as a terminal error and never retries or follows redirects implicitly', function (): void {
    foreach ([[410, 'interval_history_retired'], [429, 'quota_exhausted'], [503, 'project_backpressure'], [302, 'unavailable']] as [$status, $code]) {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['code' => $code], $status, ['Location' => 'https://foreign.test'])]);
        $client = new HttpCountIntervals($http, 'https://signals.test', 'key');
        try {
            $client->ingest(intervalFixture()->series, [intervalFixture()]);
            throw new RuntimeException('Failure was interpreted as acceptance');
        } catch (SignalsFailure $failure) {
            expect($failure->status)->toBe($status)->and($failure->errorCode)->toBe($code);
        }
        $http->assertSentCount(1);
    }
});

it('does not leak upstream codes or connection exception details', function (): void {
    foreach ([false, true] as $connectionFailure) {
        $http = new Factory;
        $http->fake(fn () => $connectionFailure ? throw new ConnectionException('secret-marker') : Factory::response(['code' => 'secret-marker', 'detail' => 'secret-marker'], 503));
        try {
            (new HttpCountIntervals($http, 'https://signals.test', 'key'))->ingest(intervalFixture()->series, [intervalFixture()]);
            throw new RuntimeException('Unexpected success');
        } catch (SignalsFailure $failure) {
            expect($failure->getMessage())->not->toContain('secret-marker')
                ->and($failure->errorCode)->toBe('unavailable');
        }
    }
});

it('refuses incomplete or inconsistent interval acknowledgements', function (): void {
    foreach (['created', 'revision', 'records', 'outcome'] as $case) {
        $receipt = intervalReceiptFixture();
        match ($case) {
            'created' => $receipt['created'] = 2,
            'revision' => $receipt['records'][0]['revision'] = 2,
            'records' => $receipt['records'] = [],
            'outcome' => $receipt['records'][0]['outcome'] = 'duplicate',
        };
        $http = new Factory;
        $http->fake(['*' => Factory::response($receipt)]);
        expect(fn () => (new HttpCountIntervals($http, 'https://signals.test', 'key'))->ingest(intervalFixture()->series, [intervalFixture()]))->toThrow(SignalsFailure::class);
    }
});

it('bounds response bodies and treats invalid JSON as a transport failure', function (): void {
    foreach ([['{"code":"quota_exhausted","detail":"'.str_repeat('x', 65536).'"}', 429], ['{invalid', 200]] as [$body, $status]) {
        $http = new Factory;
        $http->fake(['*' => Factory::response($body, $status)]);
        try {
            (new HttpCountIntervals($http, 'https://signals.test', 'key'))->ingest(intervalFixture()->series, [intervalFixture()]);
            throw new RuntimeException('Malformed response was accepted');
        } catch (SignalsFailure $failure) {
            expect($failure->errorCode)->toBe('malformed_response');
        }
    }
});
