<?php

use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\Erasure;
use Cbox\Signals\Data\ErasureState;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\MetricBatch;
use Cbox\Signals\Data\MetricKind;
use Cbox\Signals\Data\MetricSample;
use Cbox\Signals\Data\OperationState;
use Cbox\Signals\Data\ReplayRequest;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\HttpSignals;
use Cbox\Signals\Testing\FakeSignals;
use Illuminate\Http\Client\Factory;

it('transmits stable occurrence and batch identities', function (): void {
    $http = new Factory;
    $http->fake(['https://signals.test/*' => Factory::response(['operation_id' => 'op_1', 'accepted_count' => 1, 'accepted_at' => '2026-11-04T12:00:00Z'], 202)]);
    $client = new HttpSignals($http, 'https://signals.test', 'test-only-key');
    $receipt = $client->events(new EventBatch('customer', [new Event('event_1', 'document.checked', 1, new DateTimeImmutable('2026-11-04T12:00:00Z'), ['allowed' => false])]), 'batch_1');
    expect($receipt->acceptedCount)->toBe(1);
    $http->assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'batch_1') && $request['events'][0]['id'] === 'event_1');
});
it('keeps unavailable and insufficient coverage distinct', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['evaluation_id' => 'ev_1', 'status' => 'insufficient_data', 'signals' => [], 'coverage' => ['identity' => 'warming_up'], 'data_as_of' => null, 'config_version' => 1])]);
    $client = new HttpSignals($http, 'https://signals.test', 'test-only-key');
    expect($client->evaluate(new EvaluationRequest('attempt_1', new Entity('customer', 'user', 'one')))->status->value)->toBe('insufficient_data');
    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => 'quota_exhausted'], 429)]);
    $client = new HttpSignals($http, 'https://signals.test', 'test-only-key');
    expect(fn () => $client->evaluate(new EvaluationRequest('attempt_2', new Entity('customer', 'user', 'one'))))->toThrow(SignalsFailure::class);
});
it('refuses malformed success and missing configuration', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['ok' => true])]);
    expect(fn () => (new HttpSignals($http, 'https://signals.test', 'key'))->evaluate(new EvaluationRequest('one', new Entity('x', 'user', 'one'))))->toThrow(SignalsFailure::class);
    expect(fn () => (new HttpSignals($http, '', ''))->evaluate(new EvaluationRequest('one', new Entity('x', 'user', 'one'))))->toThrow(SignalsFailure::class);
});

it('uses durable metric and replay contracts with stable request keys', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['operation_id' => 'op_metric', 'accepted_count' => 1, 'accepted_at' => '2026-11-04T12:00:00Z'], 202)]);
    $client = new HttpSignals($http, 'https://signals.test', 'test-only-key');
    $at = new DateTimeImmutable('2026-11-04T12:00:00.123456Z');
    $sample = new MetricSample('memory-1', 'runtime.memory', 128, 'bytes', MetricKind::Gauge, $at, new Entity('shop', 'instance', 'worker'));
    expect($client->metrics(new MetricBatch('runtime', [$sample]), 'metrics-stable')->operationId)->toBe('op_metric');
    $http->assertSent(fn ($request) => $request->url() === 'https://signals.test/v1/metrics:batch' && $request->hasHeader('Idempotency-Key', 'metrics-stable') && str_contains($request->body(), '"dimensions":{}') && str_contains($request->body(), '123456'));
    $client->replay(new ReplayRequest($at->modify('-1 hour'), $at, 1, 1, 'memory'), 'replay-stable');
    $http->assertSent(fn ($request) => $request->url() === 'https://signals.test/v1/replays' && $request->hasHeader('Idempotency-Key', 'replay-stable') && $request['configuration_revision'] === 1);
});

it('never invents successful results in its test fake', function (): void {
    $fake = new FakeSignals;
    $request = new EvaluationRequest('attempt', new Entity('shop', 'user', 'person'));
    expect(fn () => $fake->evaluate($request))->toThrow(LogicException::class);
    $fake->queueEvaluation(new SignalsFailure('quota_exhausted', 429));
    expect(fn () => $fake->evaluate($request))->toThrow(SignalsFailure::class);
    expect($fake->evaluationCalls)->toHaveCount(2);
});

it('reads typed completion and replay results without confusing them with acceptance', function (): void {
    $http = new Factory;
    $result = ['mode' => 'isolated', 'configuration_revision' => 2, 'detector_id' => 'memory', 'results' => ['customer' => ['status' => 'ready', 'data_as_of' => '2026-11-04T12:00:00Z', 'finding' => ['type' => 'runtime.memory_growth', 'severity' => 'medium', 'reasons' => ['growth'], 'evidence' => [['measure' => 'bytes', 'value' => 128, 'occurrences' => ['sample-1']]]]]], 'live_deliveries' => false, 'billable_events' => 0];
    $http->fake(['*' => Factory::response(['id' => 'op_1', 'status' => 'completed', 'accepted_count' => 12, 'error_code' => null, 'result' => $result])]);
    $client = new HttpSignals($http, 'https://signals.test', 'key');
    $operation = $client->operation('op_1');
    expect($operation->status)->toBe(OperationState::Completed)
        ->and($operation->result->results['customer']->finding->evidence[0]->value)->toBe(128.0);
    $http->assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://signals.test/v1/operations/op_1');
    foreach (['live_deliveries' => true, 'billable_events' => 1, 'mode' => 'live'] as $key => $value) {
        $bad = $result;
        $bad[$key] = $value;
        $http = new Factory;
        $http->fake(['*' => Factory::response(['id' => 'op_1', 'status' => 'completed', 'accepted_count' => 12, 'error_code' => null, 'result' => $bad])]);
        expect(fn () => (new HttpSignals($http, 'https://signals.test', 'key'))->operation('op_1'))->toThrow(SignalsFailure::class);
    }
});

it('requests erasure and polls pending history without claiming deletion is complete', function (): void {
    $http = new Factory;
    $http->fakeSequence()->push(['id' => 'erase_1', 'status' => 'pending'], 202)->push(['id' => 'erase_1', 'status' => 'pending_history'])->push(['id' => 'erase_1', 'status' => 'pending_configuration'])->push(['id' => 'erase_1', 'status' => 'completed']);
    $client = new HttpSignals($http, 'https://signals.test', 'key');
    $request = $client->erase(new Entity('shop', 'user', 'one'));
    expect($request->status)->toBe(ErasureState::Pending)
        ->and($client->erasure($request->id)->status)->toBe(ErasureState::PendingHistory)
        ->and($client->erasure($request->id)->status)->toBe(ErasureState::PendingConfiguration)
        ->and($client->erasure($request->id)->status)->toBe(ErasureState::Completed);
    $http->assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://signals.test/v1/erasures' && $request['subject']['namespace'] === 'shop');
    $fake = new FakeSignals;
    expect(fn () => $fake->erase(new Entity('shop', 'user', 'one')))->toThrow(LogicException::class);
    $fake->queueErasure('erasure', new Erasure('erase_1', ErasureState::PendingHistory));
    expect($fake->erasure('erase_1')->status)->toBe(ErasureState::PendingHistory);
    expect(fn () => $fake->operation('op_1'))->toThrow(LogicException::class);
});

it('rejects malformed states and timestamps as transport failures', function (): void {
    foreach (['tomorrow', '2026-02-30T12:00:00Z', 'not-a-date'] as $date) {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['operation_id' => 'op_1', 'accepted_count' => 1, 'accepted_at' => $date], 202)]);
        expect(fn () => (new HttpSignals($http, 'https://signals.test', 'key'))->events(new EventBatch('customer', []), 'key'))->toThrow(SignalsFailure::class);
    }
    $http = new Factory;
    $http->fake(['*' => Factory::response(['id' => 'erase_1', 'status' => 'maybe'])]);
    expect(fn () => (new HttpSignals($http, 'https://signals.test', 'key'))->erasure('erase_1'))->toThrow(SignalsFailure::class);
});
