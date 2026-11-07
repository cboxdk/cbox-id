<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class Evaluation
{
    /** @param list<Signal> $signals
     * @param array<string,string> $coverage */
    public function __construct(public string $id, public EvaluationStatus $status, public array $signals, public array $coverage, public ?DateTimeImmutable $dataAsOf, public int $configVersion) {}
}
