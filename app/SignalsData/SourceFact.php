<?php

declare(strict_types=1);

namespace App\SignalsData;

use Carbon\CarbonImmutable;

/** Only typed producers construct these; callers cannot submit arbitrary event payloads. */
final readonly class SourceFact
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $subjects
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $data,
        public CarbonImmutable $occurredAt,
        public array $subjects = [],
        public ?string $organizationId = null,
    ) {}
}
