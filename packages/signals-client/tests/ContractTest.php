<?php

use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\MetricBatch;
use Cbox\Signals\Data\MetricKind;
use Cbox\Signals\Data\MetricSample;
use Cbox\Signals\Data\ReplayRequest;
use Opis\JsonSchema\Validator;

it('validates the shared engine fixtures with an independent PHP JSON Schema runtime', function (): void {
    $document = json_decode(file_get_contents(__DIR__.'/Contracts/schemas.json'), flags: JSON_THROW_ON_ERROR);
    $fixtures = json_decode(file_get_contents(__DIR__.'/Contracts/fixtures.json'), flags: JSON_THROW_ON_ERROR);
    foreach ($fixtures as $fixture) {
        $schema = clone $document;
        $schema->{'$ref'} = '#/$defs/'.$fixture->schema;
        $result = (new Validator)->validate($fixture->data, $schema);
        expect($result->isValid())->toBe($fixture->valid, $fixture->name);
    }
});

it('serializes public DTOs according to the shared wire contract', function (): void {
    $document = json_decode(file_get_contents(__DIR__.'/Contracts/schemas.json'), flags: JSON_THROW_ON_ERROR);
    $values = [
        'EventBatch' => (new EventBatch('shop', [new Event('one', 'shop.document_blocked', 1, new DateTimeImmutable('2026-11-04T12:00:00Z'), ['customer' => 'alice'])]))->toArray(),
        'MetricBatch' => (new MetricBatch('runtime', [new MetricSample('sample', 'runtime.memory', 128, 'bytes', MetricKind::Gauge, new DateTimeImmutable('2026-11-04T12:00:00Z'), new Entity('shop', 'instance', 'worker'))]))->toArray(),
        'ReplayRequest' => (new ReplayRequest(new DateTimeImmutable('2026-11-04T11:00:00Z'), new DateTimeImmutable('2026-11-04T12:00:00Z'), 1, 1, 'memory'))->toArray(),
        'EvaluationRequest' => (new EvaluationRequest('attempt', new Entity('shop', 'user', 'alice')))->toArray(),
    ];
    foreach ($values as $name => $value) {
        $schema = clone $document;
        $schema->{'$ref'} = '#/$defs/'.$name;
        $wire = json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        expect((new Validator)->validate($wire, $schema)->isValid())->toBeTrue($name);
    }
});

it('retains downstream tenant identity without changing legacy entity payloads', function (): void {
    expect((new Entity('storefront', 'user', 'same'))->toArray())->toBe(['namespace' => 'storefront', 'type' => 'user', 'id' => 'same']);
    expect((new Entity('storefront', 'user', 'same', 'one'))->toArray())->toBe(['tenant_id' => 'one', 'namespace' => 'storefront', 'type' => 'user', 'id' => 'same']);
});
