<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

/** A persisted finding, distinct from the compact evaluation summary. */
readonly class SignalRecord
{
    /** @param list<string> $reasons
     * @param list<Evidence> $evidence */
    public function __construct(
        public string $id, public string $project, public string $environment,
        public Entity $subject, public string $detectorId, public int $detectorVersion,
        public int $mappingVersion, public int $configurationRevision,
        public string $type, public string $severity, public array $reasons,
        public array $evidence, public string $status, public int $revision,
        public string $mode, public string $dataQuality,
        public DateTimeImmutable $firstSeenAt, public DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $entity = Wire::object($data['subject'] ?? null);

        return new self(
            Wire::text($data['id'] ?? null), Wire::text($data['project'] ?? null), Wire::text($data['environment'] ?? null),
            new Entity(Wire::text($entity['namespace'] ?? null), Wire::text($entity['type'] ?? null), Wire::text($entity['id'] ?? null), isset($entity['tenant_id']) ? Wire::text($entity['tenant_id']) : ''),
            Wire::text($data['detector_id'] ?? null), Wire::integer($data['detector_version'] ?? null, 1),
            Wire::integer($data['mapping_version'] ?? null), Wire::integer($data['configuration_revision'] ?? null, 1),
            Wire::text($data['type'] ?? null), Wire::text($data['severity'] ?? null), Wire::strings($data['reasons'] ?? null),
            array_map(static fn (mixed $item): Evidence => Evidence::fromArray(Wire::object($item)), Wire::items($data['evidence'] ?? null)),
            Wire::text($data['status'] ?? null), Wire::integer($data['revision'] ?? null, 1),
            Wire::text($data['mode'] ?? null), Wire::text($data['data_quality'] ?? null),
            Wire::date($data['first_seen_at'] ?? null), Wire::date($data['last_seen_at'] ?? null), Wire::date($data['expires_at'] ?? null),
        );
    }
}
