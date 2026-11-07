<?php

declare(strict_types=1);

namespace Cbox\Signals;

use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Data\ConnectionState;
use Cbox\Signals\Data\DetectorState;
use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\Erasure;
use Cbox\Signals\Data\Evaluation;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\EvaluationStatus;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\MetricBatch;
use Cbox\Signals\Data\Operation;
use Cbox\Signals\Data\Receipt;
use Cbox\Signals\Data\ReplayRequest;
use Cbox\Signals\Data\RetentionState;
use Cbox\Signals\Data\Signal;
use Cbox\Signals\Data\SignalDetail;
use Cbox\Signals\Data\SignalFilter;
use Cbox\Signals\Data\SignalPage;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Support\Wire;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

class HttpSignals implements Signals
{
    public function __construct(private readonly Factory $http, private readonly string $url, private readonly string $token, private readonly float $timeout = 2) {}

    public function signals(?SignalFilter $filter = null): SignalPage
    {
        return SignalPage::fromArray($this->request('GET', '/v1/signals', ($filter ?? new SignalFilter)->toArray()));
    }

    public function signal(string $id): SignalDetail
    {
        return SignalDetail::fromArray($this->request('GET', '/v1/signals/'.rawurlencode($id), []));
    }

    public function detectors(): array
    {
        $data = $this->request('GET', '/v1/detectors/status', []);

        return array_map(static fn (mixed $item): DetectorState => DetectorState::fromArray(Wire::object($item)), Wire::items($data['data'] ?? null));
    }

    public function connections(): array
    {
        $data = $this->request('GET', '/v1/connections', []);

        return array_map(static fn (mixed $item): ConnectionState => ConnectionState::fromArray(Wire::object($item)), Wire::items($data['data'] ?? null));
    }

    public function retention(): RetentionState
    {
        return RetentionState::fromArray($this->request('GET', '/v1/retention', []));
    }

    public function events(EventBatch $batch, string $idempotencyKey): Receipt
    {
        return $this->receipt($this->post('/v1/events:batch', $batch->toArray(), $idempotencyKey));
    }

    public function metrics(MetricBatch $batch, string $idempotencyKey): Receipt
    {
        return $this->receipt($this->post('/v1/metrics:batch', $batch->toArray(), $idempotencyKey));
    }

    public function replay(ReplayRequest $request, string $idempotencyKey): Receipt
    {
        return $this->receipt($this->post('/v1/replays', $request->toArray(), $idempotencyKey));
    }

    public function operation(string $id): Operation
    {
        return Operation::fromArray($this->request('GET', '/v1/operations/'.rawurlencode($id), []));
    }

    public function erase(Entity $subject): Erasure
    {
        return Erasure::fromArray($this->post('/v1/erasures', ['subject' => $subject->toArray()]));
    }

    public function erasure(string $id): Erasure
    {
        return Erasure::fromArray($this->request('GET', '/v1/erasures/'.rawurlencode($id), []));
    }

    /** @param array<string,mixed> $data */
    private function receipt(array $data): Receipt
    {
        if (! is_string($data['operation_id'] ?? null) || ! is_int($data['accepted_count'] ?? null) || ! is_string($data['accepted_at'] ?? null)) {
            throw new SignalsFailure('malformed_response', 503);
        }

        return new Receipt(Wire::text($data['operation_id']), Wire::integer($data['accepted_count']), Wire::date($data['accepted_at']));
    }

    public function evaluate(EvaluationRequest $request): Evaluation
    {
        $data = $this->post('/v1/evaluations', $request->toArray());
        $status = is_string($data['status'] ?? null) ? EvaluationStatus::tryFrom($data['status']) : null;
        if ($status === null || ! is_string($data['evaluation_id'] ?? null) || ! is_array($data['signals'] ?? null) || ! is_array($data['coverage'] ?? null) || ! is_int($data['config_version'] ?? null)) {
            throw new SignalsFailure('malformed_response', 503);
        }
        $signals = [];
        foreach ($data['signals'] as $signal) {
            if (! is_array($signal) || ! is_string($signal['type'] ?? null) || ! is_string($signal['severity'] ?? null) || ! is_array($signal['reasons'] ?? null) || ! is_string($signal['expires_at'] ?? null)) {
                throw new SignalsFailure('malformed_response', 503);
            }$reasons = [];
            foreach ($signal['reasons'] as $reason) {
                if (! is_string($reason)) {
                    throw new SignalsFailure('malformed_response', 503);
                }$reasons[] = $reason;
            }$signals[] = new Signal($signal['type'], $signal['severity'], $reasons, Wire::date($signal['expires_at']), is_string($signal['id'] ?? null) ? $signal['id'] : null, is_int($signal['revision'] ?? null) ? $signal['revision'] : null);
        }
        $coverage = [];
        foreach ($data['coverage'] as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new SignalsFailure('malformed_response', 503);
            }$coverage[$key] = $value;
        }

        return new Evaluation($data['evaluation_id'], $status, $signals, $coverage, isset($data['data_as_of']) ? Wire::date($data['data_as_of']) : null, $data['config_version']);
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function post(string $path, array $body, ?string $key = null): array
    {
        return $this->request('POST', $path, $body, $key);
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function request(string $method, string $path, array $body, ?string $key = null): array
    {
        if ($this->url === '' || $this->token === '') {
            throw new SignalsFailure('not_configured', 503);
        }
        try {
            $request = $this->http->withToken($this->token)->acceptJson()->timeout($this->timeout)->connectTimeout(min($this->timeout, 1))->withoutRedirecting();
            if ($key !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $key]);
            }$response = $method === 'GET' ? $request->get(rtrim($this->url, '/').$path, $body) : $request->post(rtrim($this->url, '/').$path, $body);
        } catch (ConnectionException) {
            throw new SignalsFailure('unavailable', 503);
        }
        $data = $response->json();
        if (! $response->successful()) {
            throw new SignalsFailure(is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'unavailable', $response->status());
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new SignalsFailure('malformed_response', 503);
        } $result = [];
        foreach ($data as $k => $v) {
            if (! is_string($k)) {
                throw new SignalsFailure('malformed_response', 503);
            }$result[$k] = $v;
        }

        return $result;
    }
}
