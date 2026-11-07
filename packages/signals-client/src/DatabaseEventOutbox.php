<?php

declare(strict_types=1);

namespace Cbox\Signals;

use Cbox\Signals\Contracts\EventOutbox;
use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Exceptions\SignalsFailure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

class DatabaseEventOutbox implements EventOutbox
{
    public function __construct(private readonly ConnectionInterface $database, private readonly Signals $signals) {}

    public function enqueue(EventBatch $batch, string $idempotencyKey): void
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey)) {
            throw new InvalidArgumentException('The outbox key must be a UUID.');
        }
        $body = json_encode($batch->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($body) > 1048576 || count($batch->events) < 1 || count($batch->events) > 500) {
            throw new InvalidArgumentException('Outbox batches require 1–500 events and at most 1 MiB.');
        }
        $hash = hash('sha256', $body);
        $count = $this->database->table('signals_event_outbox')->insertOrIgnore(['id' => $idempotencyKey, 'body_hash' => $hash, 'body' => $body, 'next_attempt_at' => now(), 'created_at' => now()]);
        if ($count === 0 && $this->database->table('signals_event_outbox')->where('id', $idempotencyKey)->value('body_hash') !== $hash) {
            throw new SignalsFailure('idempotency_conflict', 409);
        }
    }

    public function dispatch(): bool
    {
        return $this->database->transaction(function (): bool {
            $row = $this->database->table('signals_event_outbox')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('created_at')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            if (! is_string($row->id) || ! is_string($row->body) || ! is_numeric($row->attempts)) {
                throw new SignalsFailure('invalid_outbox', 503);
            }
            $query = $this->database->table('signals_event_outbox')->where('id', $row->id);
            try {
                $receipt = $this->signals->events($this->batch($row->body), $row->id);
                $query->update(['status' => 'sent', 'sent_at' => now(), 'operation_id' => $receipt->operationId, 'error_code' => null]);
            } catch (SignalsFailure $failure) {
                $attempts = intval($row->attempts) + 1;
                $retry = in_array($failure->status, [408, 429, 500, 502, 503, 504], true) && $attempts < 24;
                $query->update(['status' => $retry ? 'pending' : 'failed', 'attempts' => $attempts, 'error_code' => $failure->errorCode, 'next_attempt_at' => now()->addSeconds(min(3600, 5 * 2 ** min($attempts, 10)))]);
            }

            return true;
        }) === true;
    }

    private function batch(string $body): EventBatch
    {
        $value = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! is_string($value['source_id'] ?? null) || ! is_array($value['events'] ?? null)) {
            throw new SignalsFailure('invalid_outbox', 422);
        }
        $events = [];
        foreach ($value['events'] as $e) {
            if (! is_array($e) || ! is_string($e['id'] ?? null) || ! is_string($e['name'] ?? null) || ! is_int($e['schema_version'] ?? null) || ! is_string($e['occurred_at'] ?? null) || ! is_array($e['data'] ?? null)) {
                throw new SignalsFailure('invalid_outbox', 422);
            }
            $data = [];
            foreach ($e['data'] as $key => $field) {
                if (! is_string($key)) {
                    throw new SignalsFailure('invalid_outbox', 422);
                } $data[$key] = $field;
            }
            $events[] = new Event($e['id'], $e['name'], $e['schema_version'], new DateTimeImmutable($e['occurred_at']), $data);
        }

        return new EventBatch($value['source_id'], $events);
    }
}
