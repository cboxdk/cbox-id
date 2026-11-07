<?php

declare(strict_types=1);

namespace Cbox\Signals\Webhooks;

use Cbox\Signals\Contracts\SignalInbox;
use Cbox\Signals\Exceptions\SignalsFailure;
use Illuminate\Database\ConnectionInterface;
use JsonException;

class DatabaseSignalInbox implements SignalInbox
{
    public function __construct(private readonly ConnectionInterface $database) {}

    public function accept(SignalEnvelope $envelope): bool
    {
        return $this->database->transaction(function () use ($envelope): bool {
            $scope = ['project' => $envelope->project, 'environment' => $envelope->environment];
            $hash = hash('sha256', $envelope->rawBody);
            $receipt = [...$scope, 'delivery_id' => $envelope->deliveryId];
            $inserted = $this->database->table('signals_inbox_receipts')->insertOrIgnore([...$receipt, 'body_hash' => $hash, 'received_at' => now()]);
            if ($inserted === 0) {
                if ($this->database->table('signals_inbox_receipts')->where($receipt)->value('body_hash') !== $hash) {
                    throw new SignalsFailure('delivery_conflict', 409);
                }

                return false;
            }
            $key = [...$scope, 'signal_id' => $envelope->signalId];
            $values = ['revision' => $envelope->revision, 'type' => $envelope->type, 'expires_at' => $envelope->expiresAt, 'body' => $envelope->rawBody, 'updated_at' => now()];
            $created = $this->database->table('signals_inbox_state')->insertOrIgnore([...$key, ...$values]);
            if ($created !== 0) {
                return true;
            }
            $row = $this->database->table('signals_inbox_state')->where($key)->lockForUpdate()->first();
            if ($row === null || ! is_numeric($row->revision)) {
                throw new SignalsFailure('invalid_stored_revision', 503);
            }
            if (intval($row->revision) >= $envelope->revision) {
                return false;
            }
            $this->database->table('signals_inbox_state')->where($key)->update($values);

            return true;
        }) === true;
    }

    public function active(string $project, string $environment): array
    {
        $result = [];
        foreach ($this->database->table('signals_inbox_state')->where('project', $project)->where('environment', $environment)->whereIn('type', ['signal.created', 'signal.updated'])->where('expires_at', '>', now())->get() as $row) {
            if (! is_string($row->body)) {
                throw new SignalsFailure('invalid_stored_envelope', 503);
            }
            try {
                $payload = json_decode($row->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new SignalsFailure('invalid_stored_envelope', 503);
            }
            if (! is_array($payload)) {
                throw new SignalsFailure('invalid_stored_envelope', 503);
            }
            $typed = [];
            foreach ($payload as $key => $value) {
                if (! is_string($key)) {
                    throw new SignalsFailure('invalid_stored_envelope', 503);
                } $typed[$key] = $value;
            }
            $result[] = SignalEnvelope::fromArray($typed, $row->body);
        }

        return $result;
    }
}
