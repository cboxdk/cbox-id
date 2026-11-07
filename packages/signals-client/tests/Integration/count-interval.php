<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Cbox\Signals\Data\CountInterval;
use Cbox\Signals\Data\IntervalCoverage;
use Cbox\Signals\Data\IntervalEntity;
use Cbox\Signals\Data\IntervalHistoryQuery;
use Cbox\Signals\Data\IntervalScope;
use Cbox\Signals\Data\IntervalSeries;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\HttpCountIntervals;
use Cbox\Signals\Support\IntervalWire;
use Illuminate\Http\Client\Factory;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$config = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
check(parse_url($config['url'], PHP_URL_HOST) === '127.0.0.1', 'Integration server must be loopback.');
$client = new HttpCountIntervals(new Factory, $config['url'], $config['token'], 5);
$series = new IntervalSeries('api', 'requests', 1);
$end = new DateTimeImmutable($config['end']);
$scope = new IntervalScope('sdk-interval', 'production', 'customer');
$owner = new IntervalEntity('api', 'user', 'owner-17');
$record = new CountInterval($scope, $series, new IntervalEntity('api', 'token', 'internal-key-17'), IntervalWire::utc($end->modify('-1 hour')), IntervalWire::utc($end), $end->format('Y-m-d\TH:i:s').'.123456789Z', '18446744073709551615', new IntervalCoverage('complete', IntervalWire::utc($end), []), [$owner]);
$query = new IntervalHistoryQuery($end->modify('-3 hours'), $end);

if ($config['phase'] === 'records') {
    $first = $client->ingest($series, [$record]);
    check($first->created === 1, 'First bucket was not created.');
    check($client->ingest($series, [$record])->duplicates === 1, 'Retained retry was not a duplicate.');
    $initial = $client->history($series, $query);
    check(count($initial->data) === 1 && $initial->data[0]->record->count === $record->count, 'Exact uint64 count did not survive storage.');
    check($initial->data[0]->record->recordedAt === $record->recordedAt, 'Source nanoseconds were changed.');
    $wire = $record->toArray();
    $wire['count'] = '0';
    $wire['revision'] = 2;
    $wire['previous_hash'] = $first->records[0]->hash;
    $correction = $client->ingest($series, [CountInterval::fromArray($wire)]);
    check($correction->corrected === 1 && $correction->created === 0, 'Correction was charged as a new bucket.');
    $old = $client->history($series, new IntervalHistoryQuery($query->from, $query->through, $initial->asOf));
    check($old->data[0]->record->count === $record->count && $old->data[0]->record->revision === 1, 'Correction rewrote an earlier snapshot.');
    $unknown = new CountInterval($scope, $series, $record->entity, IntervalWire::utc($end->modify('-3 hours')), IntervalWire::utc($end->modify('-2 hours')), $record->recordedAt, null, new IntervalCoverage('unknown', null, ['source_unavailable']), [$owner]);
    $partial = new CountInterval($scope, $series, $record->entity, IntervalWire::utc($end->modify('-2 hours')), IntervalWire::utc($end->modify('-1 hour')), $record->recordedAt, '17', new IntervalCoverage('partial', IntervalWire::utc($end->modify('-90 minutes')), ['source_unavailable']), [$owner]);
    check($client->ingest($series, [$unknown, $partial])->created === 2, 'Incomplete intervals were not preserved.');
    $page = $client->history($series, new IntervalHistoryQuery($query->from, $query->through, limit: 1));
    $rows = $page->data;
    $asOf = $page->asOf;
    while ($page->nextCursor !== null) {
        check(count($rows) < 3, 'Pagination did not terminate.');
        $page = $client->history($series, new IntervalHistoryQuery($query->from, $query->through, $asOf, $page->nextCursor, 1));
        $rows = [...$rows, ...$page->data];
    }
    check(count($rows) === 3, 'Missing or repeated interval page.');
    check($rows[0]->record->count === null && $rows[0]->record->coverage->status === 'unknown', 'Unknown became zero.');
    check($rows[1]->record->count === '17' && $rows[1]->record->coverage->status === 'partial', 'Partial became complete.');
    check($rows[2]->record->count === '0' && $rows[2]->record->revision === 2, 'Observed zero or correction was lost.');
    $foreign = new HttpCountIntervals(new Factory, $config['url'], $config['foreign_token'], 5);
    foreach ([fn () => $foreign->history($series, $query), fn () => $foreign->ingest($series, [$record])] as $attempt) {
        try {
            $attempt();
            throw new RuntimeException('Foreign credential crossed the project boundary.');
        } catch (SignalsFailure $failure) {
            check($failure->status === 403, 'Foreign operation did not fail with 403.');
        }
    }
    echo json_encode(['passed' => true, 'buckets' => 3, 'versions' => 4, 'old_as_of' => IntervalWire::utc($initial->asOf), 'correction_hash' => $correction->records[0]->hash], JSON_THROW_ON_ERROR);
} elseif ($config['phase'] === 'erased') {
    check($client->history($series, new IntervalHistoryQuery($query->from, $query->through, new DateTimeImmutable($config['old_as_of'])))->data === [], 'Historical read bypassed erasure.');
    $wire = $record->toArray();
    $wire['revision'] = 3;
    $wire['previous_hash'] = $config['correction_hash'];
    $wire['privacy_subjects'] = [];
    try {
        $client->ingest($series, [CountInterval::fromArray($wire)]);
        throw new RuntimeException('Omitting the former owner bypassed erasure.');
    } catch (SignalsFailure $failure) {
        check($failure->status === 422 && $failure->errorCode === 'subject_erased', 'Erased retry did not fail closed.');
    }
    echo json_encode(['passed' => true, 'historical_rows' => 0, 'erased_replay_refused' => true], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown integration phase.');
}
