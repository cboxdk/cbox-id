<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

/** Outcome of one reconcile pass; secrets never appear here. */
final readonly class ManagedReconciliation
{
    /**
     * @param  list<string>  $enrolled  owner ids enrolled for the first time
     * @param  list<string>  $rotated  owner ids whose secret was replaced
     * @param  list<string>  $unchanged  owner ids already enrolled and matching
     * @param  list<string>  $revoked  owner ids revoked
     * @param  array<string,string>  $failed  owner id => failure code
     */
    public function __construct(
        public array $enrolled,
        public array $rotated,
        public array $unchanged,
        public array $revoked,
        public array $failed,
    ) {}

    public function clean(): bool
    {
        return $this->failed === [];
    }
}
