<?php

declare(strict_types=1);

namespace Cbox\Signals\Webhooks;

use Cbox\Signals\Exceptions\SignalsFailure;
use DateTimeImmutable;
use Throwable;

readonly class SignalEnvelope
{
    /** @param array<string,mixed> $data */
    public function __construct(public string $deliveryId, public string $type, public DateTimeImmutable $occurredAt, public string $project, public string $environment, public string $signalId, public int $revision, public DateTimeImmutable $expiresAt, public array $data, public string $rawBody) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload, string $body): self
    {
        $data = $payload['data'] ?? null;
        if (! is_string($payload['delivery_id'] ?? null) || $payload['delivery_id'] === '' || ! is_string($payload['type'] ?? null) || ! in_array($payload['type'], ['signal.created', 'signal.updated', 'signal.resolved', 'signal.expired'], true) || ! is_string($payload['occurred_at'] ?? null) || ! is_array($data)) {
            throw new SignalsFailure('invalid_envelope', 422);
        }
        foreach (['project', 'environment', 'id', 'expires_at'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new SignalsFailure('invalid_envelope', 422);
            }
        }
        if (! is_int($data['revision'] ?? null) || $data['revision'] < 1) {
            throw new SignalsFailure('invalid_revision', 422);
        }
        $typed = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new SignalsFailure('invalid_envelope', 422);
            } $typed[$key] = $value;
        }
        try {
            $occurred = new DateTimeImmutable($payload['occurred_at']);
            $expires = new DateTimeImmutable($data['expires_at']);
        } catch (Throwable) {
            throw new SignalsFailure('invalid_timestamp', 422);
        }

        return new self($payload['delivery_id'], $payload['type'], $occurred, $data['project'], $data['environment'], $data['id'], $data['revision'], $expires, $typed, $body);
    }
}
