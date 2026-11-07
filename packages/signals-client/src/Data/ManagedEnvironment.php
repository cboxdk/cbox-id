<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;
use DateTimeInterface;

/** One source-application environment as Signals should see it. */
final readonly class ManagedEnvironment
{
    public function __construct(
        /** The application's stable environment id; becomes the enrolment owner id. */
        public string $ownerId,
        /** Cbox ID organization id that owns the environment. */
        public string $organization,
        /** Absolute URL of the environment's Signals source feed. */
        public string $url,
        /** Earliest occurrence Signals may import. */
        public DateTimeImmutable $importFrom,
    ) {}

    /** @return array{organization: string, url: string, import_from: string} */
    public function toArray(): array
    {
        return ['organization' => $this->organization, 'url' => $this->url, 'import_from' => $this->importFrom->format(DateTimeInterface::RFC3339_EXTENDED)];
    }
}
