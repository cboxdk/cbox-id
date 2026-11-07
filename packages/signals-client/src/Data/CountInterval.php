<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\IntervalWire;
use Cbox\Signals\Support\Wire;
use InvalidArgumentException;
use stdClass;

readonly class CountInterval
{
    /**
     * @param  list<IntervalEntity>  $privacySubjects
     * @param  array<string,string>  $dimensions
     */
    public function __construct(public IntervalScope $scope, public IntervalSeries $series, public IntervalEntity $entity, public string $start, public string $end, public string $recordedAt, public ?string $count, public IntervalCoverage $coverage, public array $privacySubjects, public array $dimensions = [], public int $revision = 1, public ?string $previousHash = null)
    {
        foreach ([$start, $end, $recordedAt] as $timestamp) {
            IntervalWire::timestamp($timestamp);
        }
        if (IntervalWire::compare($end, $start) <= 0 || IntervalWire::compare($recordedAt, $end) < 0 || $revision < 1 || $revision > 64 || ($revision === 1) !== ($previousHash === null)) {
            throw new InvalidArgumentException('Invalid interval boundaries or revision.');
        }
        if ($previousHash !== null && ! preg_match('/^[0-9a-f]{64}$/D', $previousHash)) {
            throw new InvalidArgumentException('Invalid previous interval hash.');
        }
        if (($coverage->status === 'unknown') !== ($count === null)) {
            throw new InvalidArgumentException('Unknown intervals have no count; observed intervals require one.');
        }
        if ($count !== null) {
            IntervalWire::count($count);
        }
        if ($coverage->observedThrough !== null) {
            $observed = $coverage->observedThrough;
            if (IntervalWire::compare($observed, $start) < 0 || IntervalWire::compare($observed, $recordedAt) > 0 || ($coverage->status === 'complete' && IntervalWire::compare($observed, $end) < 0)) {
                throw new InvalidArgumentException('Coverage does not match the closed interval.');
            }
        }
        if (count($privacySubjects) > 16 || count($dimensions) > 12) {
            throw new InvalidArgumentException('Interval dimensions or privacy dependencies exceed limits.');
        }
        $owners = [];
        foreach ($privacySubjects as $subject) {
            $key = json_encode($subject->toArray(), JSON_THROW_ON_ERROR);
            if (isset($owners[$key])) {
                throw new InvalidArgumentException('Duplicate interval privacy dependency.');
            }
            $owners[$key] = true;
        }
        foreach ($dimensions as $key => $value) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key) || $value === '' || strlen($value) > 128) {
                throw new InvalidArgumentException('Invalid registered interval dimension.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['contract_version' => 'count-interval/1', 'scope' => $this->scope->toArray(), 'source_id' => $this->series->sourceId, 'series_id' => $this->series->id, 'series_revision' => $this->series->revision, 'entity' => $this->entity->toArray(), 'privacy_subjects' => array_map(static fn (IntervalEntity $subject): array => $subject->toArray(), $this->privacySubjects), 'dimensions' => (object) $this->dimensions, 'start' => $this->start, 'end' => $this->end, 'recorded_at' => $this->recordedAt, 'revision' => $this->revision, 'previous_hash' => $this->previousHash, 'count' => $this->count, 'coverage' => $this->coverage->toArray()];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['contract_version'] ?? null) !== 'count-interval/1' || ! array_key_exists('count', $data) || ! array_key_exists('previous_hash', $data)) {
            Wire::fail();
        }
        $dimensions = [];
        $rawDimensions = $data['dimensions'] ?? null;
        if ($rawDimensions instanceof stdClass) {
            $rawDimensions = get_object_vars($rawDimensions);
        }
        foreach (Wire::object($rawDimensions) as $key => $value) {
            $dimensions[$key] = Wire::text($value);
        }

        return new self(IntervalScope::fromArray(Wire::object($data['scope'] ?? null)), new IntervalSeries(Wire::text($data['source_id'] ?? null), Wire::text($data['series_id'] ?? null), Wire::integer($data['series_revision'] ?? null, 1)), IntervalEntity::fromArray(Wire::object($data['entity'] ?? null)), Wire::text($data['start'] ?? null), Wire::text($data['end'] ?? null), Wire::text($data['recorded_at'] ?? null), $data['count'] === null ? null : Wire::text($data['count']), IntervalCoverage::fromArray(Wire::object($data['coverage'] ?? null)), array_map(static fn (mixed $item): IntervalEntity => IntervalEntity::fromArray(Wire::object($item)), Wire::items($data['privacy_subjects'] ?? null)), $dimensions, Wire::integer($data['revision'] ?? null, 1), $data['previous_hash'] === null ? null : Wire::text($data['previous_hash']));
    }
}
