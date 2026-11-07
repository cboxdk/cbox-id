<?php

use Cbox\Signals\Data\SignalFilter;
use Cbox\Signals\Data\SignalPage;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\HttpSignals;
use Cbox\Signals\Testing\FakeSignals;
use Illuminate\Http\Client\Factory;

function persistedSignal(): array
{
    return ['id' => 'sig_1', 'project' => 'project', 'environment' => 'test', 'subject' => ['namespace' => 'customer', 'type' => 'instance', 'id' => 'one'], 'detector_id' => 'memory', 'detector_version' => 1, 'mapping_version' => 0, 'configuration_revision' => 2, 'type' => 'runtime.memory_growth', 'severity' => 'medium', 'reasons' => ['sustained_growth'], 'evidence' => [['measure' => 'memory_growth_bytes', 'value' => 150, 'occurrences' => ['source:sample']]], 'status' => 'expired', 'revision' => 4, 'mode' => 'observe', 'data_quality' => 'degraded', 'first_seen_at' => '2026-11-04T10:00:00Z', 'last_seen_at' => '2026-11-04T10:05:00Z', 'expires_at' => '2026-11-04T10:20:00Z'];
}

it('reads persisted signals with explicit filters cursor and bounded detail history', function (): void {
    $http = new Factory;
    $http->fakeSequence()->push(['data' => [persistedSignal()], 'next_cursor' => 'sig_1'])->push([...persistedSignal(), 'revisions' => [['revision' => 4, 'occurred_at' => '2026-11-04T10:20:00Z', 'status' => 'expired']], 'deliveries' => [['id' => 'delivery', 'destination_id' => 'webhook', 'revision' => 4, 'status' => 'pending', 'attempts' => 0, 'last_status' => null, 'next_attempt' => '2026-11-04T10:20:00Z']], 'history_limit' => 100, 'recent_observations' => [['source' => 'shop', 'event_id' => 'checked', 'observation_type' => 'access.attempt', 'occurred_at' => '2026-11-04T10:20:00Z']]])->push(['data' => [], 'next_cursor' => null]);
    $client = new HttpSignals($http, 'https://signals.test', 'read-key');
    $page = $client->signals(new SignalFilter(severity: 'medium', status: 'expired', entity: 'one'));
    expect($page->nextCursor)->toBe('sig_1')->and($page->data[0]->mappingVersion)->toBe(0)->and($page->data[0]->evidence[0]->value)->toBe(150.0);
    $detail = $client->signal('sig_1');
    expect($detail->recentObservations[0]->eventId)->toBe('checked');
    expect($detail->signal->revision)->toBe(4)->and($detail->revisions[0]->status)->toBe('expired')->and($detail->deliveries[0]->lastStatus)->toBeNull()->and($detail->historyLimit)->toBe(100);
    expect($client->signals(new SignalFilter(after: $page->nextCursor))->data)->toBe([]);
    $http->assertSent(fn ($request) => str_contains($request->url(), 'severity=medium') && $request->hasHeader('Authorization', 'Bearer read-key'));
    $http->assertSent(fn ($request) => str_contains($request->url(), 'after=sig_1'));
});

it('preserves warming detector and failed source states rather than claiming success', function (): void {
    $http = new Factory;
    $http->fakeSequence()->push(['data' => [['id' => 'probe', 'mode' => 'active', 'status' => 'warming_up', 'entities' => 0, 'counts' => [], 'data_as_of' => null]]])->push(['data' => [['id' => 'id', 'cursor' => '', 'imported_count' => 0, 'backfill_limit' => 10, 'import_started_at' => '2026-11-04T10:00:00Z', 'last_success_at' => null, 'last_attempt_at' => '2026-11-04T10:01:00Z', 'latest_event_at' => null, 'error_code' => 'source_unauthorized', 'pending_operation' => null]]]);
    $client = new HttpSignals($http, 'https://signals.test', 'read-key');
    expect($client->detectors()[0]->dataAsOf)->toBeNull();
    $source = $client->connections()[0];
    expect($source->cursor)->toBe('')->and($source->errorCode)->toBe('source_unauthorized')->and($source->lastSuccessAt)->toBeNull();
});

it('rejects malformed reads and retains API denial status', function (): void {
    $http = new Factory;
    $http->fakeSequence()->push(['data' => [persistedSignal()], 'next_cursor' => 1])->push([...persistedSignal(), 'revision' => -1])->push(['data' => [['id' => 'missing-status']]])->push(['code' => 'forbidden'], 403);
    $client = new HttpSignals($http, 'https://signals.test', 'read-key');
    expect(fn () => $client->signals())->toThrow(SignalsFailure::class);
    expect(fn () => $client->signal('sig_1'))->toThrow(SignalsFailure::class);
    expect(fn () => $client->detectors())->toThrow(SignalsFailure::class);
    try {
        $client->connections();
        test()->fail('Expected scope refusal');
    } catch (SignalsFailure $error) {
        expect($error->status)->toBe(403);
    }
});

it('requires explicit test responses for each read surface', function (): void {
    $fake = new FakeSignals;
    foreach (['signals', 'detectors', 'connections'] as $method) {
        expect(fn () => $fake->$method())->toThrow(LogicException::class);
    }
    expect(fn () => $fake->signal('unknown'))->toThrow(LogicException::class);
    $fake->queueSignals(new SignalPage([], null))->queueSignal(new SignalsFailure('not_found', 404))->queueDetectors([])->queueConnections([]);
    expect($fake->signals()->data)->toBe([])->and($fake->detectors())->toBe([])->and($fake->connections())->toBe([]);
    expect(fn () => $fake->signal('unknown'))->toThrow(SignalsFailure::class);
});

it('reads pending retention without claiming history completion and refuses malformed success', function (): void {
    $data = ['retention_days' => 1, 'status' => 'pending_history', 'operational_purged_before' => '2026-11-03T10:00:00Z', 'history_purged_before' => null, 'last_completed_at' => null, 'next_run_at' => '2026-11-04T11:00:00Z'];
    $http = new Factory;
    $http->fakeSequence()->push($data)->push([...$data, 'retention_days' => 0])->push(['status' => 'ready'])->push([...$data, 'status' => 'invented']);
    $client = new HttpSignals($http, 'https://signals.test', 'read-key');
    $state = $client->retention();
    expect($state->days)->toBe(1)->and($state->status)->toBe('pending_history')->and($state->historyPurgedBefore)->toBeNull()->and($state->lastCompletedAt)->toBeNull();
    $http->assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://signals.test/v1/retention');
    expect(fn () => $client->retention())->toThrow(SignalsFailure::class);
    expect(fn () => $client->retention())->toThrow(SignalsFailure::class);
    expect(fn () => $client->retention())->toThrow(SignalsFailure::class);
    $fake = (new FakeSignals)->queueRetention($state);
    expect($fake->retention())->toBe($state)->and($fake->retentionCalls)->toBe(1);
    expect(fn () => $fake->retention())->toThrow(LogicException::class);
});
