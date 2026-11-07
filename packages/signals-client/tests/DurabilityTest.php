<?php

use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\Receipt;
use Cbox\Signals\DatabaseEventOutbox;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Testing\FakeSignals;
use Cbox\Signals\Webhooks\DatabaseSignalInbox;
use Cbox\Signals\Webhooks\SignalEnvelope;
use Cbox\Signals\Webhooks\VerifySignalWebhook;
use Cbox\WebhookSignature\Exceptions\SignatureVerificationFailed;
use Cbox\WebhookSignature\Schemes\CboxScheme;
use Cbox\WebhookSignature\ValueObjects\SecretSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    $migration = require __DIR__.'/../database/migrations/2026_11_04_000001_create_signals_client_tables.php';
    $migration->up();
});

function envelope(int $revision, string $type = 'signal.updated', string $expiry = '+1 hour', ?string $delivery = null): SignalEnvelope
{
    $body = json_encode(['delivery_id' => $delivery ?? Str::uuid()->toString(), 'type' => $type, 'occurred_at' => date(DATE_ATOM), 'data' => ['id' => 'sig_one', 'project' => 'project', 'environment' => 'sandbox', 'revision' => $revision, 'expires_at' => (new DateTimeImmutable($expiry))->format(DATE_ATOM)]], JSON_THROW_ON_ERROR);

    return SignalEnvelope::fromArray(json_decode($body, true, flags: JSON_THROW_ON_ERROR), $body);
}

it('deduplicates deliveries and prevents late revisions or retries reviving expired signals', function (): void {
    $inbox = new DatabaseSignalInbox(DB::connection());
    $active = envelope(2);
    expect($inbox->accept($active))->toBeTrue()->and($inbox->accept($active))->toBeFalse()->and($inbox->active('project', 'sandbox'))->toHaveCount(1)->and($inbox->active('project', 'production'))->toBeEmpty();
    expect($inbox->accept(envelope(3, 'signal.expired', '-1 second')))->toBeTrue();
    expect($inbox->accept(envelope(1, 'signal.created')))->toBeFalse()->and($inbox->active('project', 'sandbox'))->toBeEmpty();
    expect($inbox->accept(envelope(4, 'signal.updated', '-1 second')))->toBeTrue()->and($inbox->active('project', 'sandbox'))->toBeEmpty();
    expect(fn () => $inbox->accept(envelope(5, delivery: $active->deliveryId)))->toThrow(SignalsFailure::class, 'delivery_conflict');
});

it('verifies exact signed bytes with rotation and rejects wrong scopes and old timestamps', function (): void {
    $envelope = envelope(1);
    $time = time();
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$envelope->rawBody, 'old-secret');
    $verifier = new VerifySignalWebhook(new CboxScheme);
    $headers = ['X-Cbox-Signature' => $signature, 'X-Cbox-Timestamp' => '1'];
    expect($verifier->verify($envelope->rawBody, $headers, SecretSet::of('new-secret', 'old-secret'), 'project', 'sandbox', $time)->signalId)->toBe('sig_one');
    expect(fn () => $verifier->verify($envelope->rawBody, $headers, SecretSet::of('old-secret'), 'other', 'sandbox', $time))->toThrow(SignalsFailure::class);
    expect(fn () => $verifier->verify($envelope->rawBody.' ', $headers, SecretSet::of('old-secret'), 'project', 'sandbox', $time))->toThrow(SignatureVerificationFailed::class);
    expect(fn () => $verifier->verify($envelope->rawBody, $headers, SecretSet::of('old-secret'), 'project', 'sandbox', $time + 301))->toThrow(SignatureVerificationFailed::class);
});

it('rolls events back with business data and resends identical batches after a lost response', function (): void {
    $client = new FakeSignals;
    $outbox = new DatabaseEventOutbox(DB::connection(), $client);
    $id = Str::uuid()->toString();
    $batch = new EventBatch('customer', [new Event('e1', 'document.checked', 1, new DateTimeImmutable, ['allowed' => false])]);
    DB::beginTransaction();
    $outbox->enqueue($batch, $id);
    DB::rollBack();
    expect(DB::table('signals_event_outbox')->count())->toBe(0);
    $outbox->enqueue($batch, $id);
    $outbox->enqueue($batch, $id);
    expect(DB::table('signals_event_outbox')->count())->toBe(1);
    $client->queueReceipt('events', new SignalsFailure('unavailable', 503));
    expect($outbox->dispatch())->toBeTrue()->and($outbox->dispatch())->toBeFalse();
    $client->queueReceipt('events', new Receipt('operation', 1, new DateTimeImmutable));
    DB::table('signals_event_outbox')->update(['next_attempt_at' => now()->subSecond()]);
    expect($outbox->dispatch())->toBeTrue()->and(DB::table('signals_event_outbox')->value('status'))->toBe('sent');
    expect($client->eventCalls)->toHaveCount(2);
    foreach ($client->eventCalls as $call) {
        expect($call['key'])->toBe($id)->and(json_encode($call['batch']->toArray(), JSON_THROW_ON_ERROR))->toBe(json_encode($batch->toArray(), JSON_THROW_ON_ERROR));
    }
});
