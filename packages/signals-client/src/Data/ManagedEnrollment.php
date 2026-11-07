<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

/** A bundled enrolment as Signals records it. */
final readonly class ManagedEnrollment
{
    public function __construct(
        public string $provider,
        public string $ownerId,
        /** Signals' source id for this environment (`managed-id-<owner>`). */
        public string $source,
        public string $url,
        public int $catalogVersion,
        public EnrollmentStatus $status,
        public DateTimeImmutable $importFrom,
        public DateTimeImmutable $enrolledAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $revokedAt,
        /** Feed generation the default pipelines were registered for; empty until observed. */
        public ?string $pipelinesGeneration,
        /** Latest reconcile problem (`partition_pending`, `partition_ambiguous`, `pipeline_rejected`); empty when healthy. */
        public ?string $errorCode,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = EnrollmentStatus::tryFrom(Wire::text($data['status'] ?? null)) ?? Wire::fail();
        $revoked = $data['revoked_at'] ?? null;
        $generation = $data['pipelines_generation'] ?? null;
        $error = $data['error_code'] ?? null;

        return new self(
            Wire::identifier($data['provider'] ?? null),
            Wire::identifier($data['owner_id'] ?? null),
            Wire::text($data['source'] ?? null),
            Wire::text($data['url'] ?? null),
            Wire::integer($data['catalog_version'] ?? null, 1),
            $status,
            Wire::date($data['import_from'] ?? null),
            Wire::date($data['enrolled_at'] ?? null),
            Wire::date($data['updated_at'] ?? null),
            $revoked === null ? null : Wire::date($revoked),
            $generation === null || $generation === '' ? null : Wire::text($generation),
            $error === null || $error === '' ? null : Wire::text($error),
        );
    }

    public function active(): bool
    {
        return $this->status === EnrollmentStatus::Enrolled;
    }
}
