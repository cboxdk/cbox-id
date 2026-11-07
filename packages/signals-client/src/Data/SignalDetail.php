<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class SignalDetail
{
    /** @param list<SignalRevision> $revisions
     * @param list<SignalDelivery> $deliveries
     * @param list<RecentObservation> $recentObservations */
    public function __construct(public SignalRecord $signal, public array $revisions, public array $deliveries, public int $historyLimit, public array $recentObservations = []) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(SignalRecord::fromArray($data),
            array_map(static fn (mixed $item): SignalRevision => SignalRevision::fromArray(Wire::object($item)), Wire::items($data['revisions'] ?? null)),
            array_map(static fn (mixed $item): SignalDelivery => SignalDelivery::fromArray(Wire::object($item)), Wire::items($data['deliveries'] ?? null)),
            Wire::integer($data['history_limit'] ?? null, 1),
            array_map(static fn (mixed $item): RecentObservation => RecentObservation::fromArray(Wire::object($item)), Wire::items($data['recent_observations'] ?? [])));
    }
}
