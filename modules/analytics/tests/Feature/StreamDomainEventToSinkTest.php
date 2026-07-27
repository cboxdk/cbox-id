<?php

declare(strict_types=1);

use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Testing\FakeReportSink;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sink = new FakeReportSink;
    app()->instance(ReportSink::class, $this->sink);
});

it('projects a delivered outbox event into one report record with the mapped metric', function (): void {
    $bus = app(EventBus::class);
    $bus->emit(new DomainEvent('user.login', ['result' => 'ok'], 'org_test'));

    $delivered = $bus->flushPending();

    expect($delivered)->toBe(1)
        ->and($this->sink->count())->toBe(1);

    $record = $this->sink->first();

    expect($record)->not->toBeNull()
        ->and($record->type)->toBe('user.login')
        ->and($record->metric)->toBe('auth.login')
        ->and($record->organizationId)->toBe('org_test')
        ->and($record->environmentId)->toBe('env_test')
        ->and($record->payloadDigest)->toMatch('/^[0-9a-f]{64}$/');
});

it('leaves the metric null for an event type that is not metered', function (): void {
    $bus = app(EventBus::class);
    $bus->emit(new DomainEvent('something.unmapped', [], 'org_test'));
    $bus->flushPending();

    expect($this->sink->first()?->metric)->toBeNull();
});

it('collapses at-least-once re-delivery to a single record (dedup on event id)', function (): void {
    $bus = app(EventBus::class);
    $row = $bus->emit(new DomainEvent('user.login', [], 'org_test'));

    $bus->flushPending();          // first delivery
    event(new EventDelivered($row)); // re-delivery of the same event

    expect($this->sink->received())->toBe(2)  // the sink saw it twice
        ->and($this->sink->count())->toBe(1); // but it collapses to one record
});
