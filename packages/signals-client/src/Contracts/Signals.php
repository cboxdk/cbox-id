<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Data\ConnectionState;
use Cbox\Signals\Data\DetectorState;
use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\Erasure;
use Cbox\Signals\Data\Evaluation;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\MetricBatch;
use Cbox\Signals\Data\Operation;
use Cbox\Signals\Data\Receipt;
use Cbox\Signals\Data\ReplayRequest;
use Cbox\Signals\Data\RetentionState;
use Cbox\Signals\Data\SignalDetail;
use Cbox\Signals\Data\SignalFilter;
use Cbox\Signals\Data\SignalPage;

interface Signals
{
    public function signals(?SignalFilter $filter = null): SignalPage;

    public function signal(string $id): SignalDetail;

    /** @return list<DetectorState> */
    public function detectors(): array;

    /** @return list<ConnectionState> */
    public function connections(): array;

    public function retention(): RetentionState;

    public function events(EventBatch $batch, string $idempotencyKey): Receipt;

    public function metrics(MetricBatch $batch, string $idempotencyKey): Receipt;

    public function replay(ReplayRequest $request, string $idempotencyKey): Receipt;

    public function evaluate(EvaluationRequest $request): Evaluation;

    public function operation(string $id): Operation;

    public function erase(Entity $subject): Erasure;

    public function erasure(string $id): Erasure;
}
