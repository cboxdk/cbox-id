<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Retention;

/**
 * The result of applying the retention policy. Deliberately has no "deleted" count:
 * retention over a hash-chained trail never removes rows (see {@see RetentionPolicy}).
 */
final readonly class RetentionReport
{
    /**
     * @param  list<string>  $checkpointedScopes  scopes anchored by a fresh checkpoint
     */
    public function __construct(
        public array $checkpointedScopes,
        public int $entriesDeleted = 0,
    ) {}

    public function checkpointCount(): int
    {
        return count($this->checkpointedScopes);
    }
}
