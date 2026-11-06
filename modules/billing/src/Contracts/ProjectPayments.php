<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Contracts;

/** Per-project subscription endpoints not yet carried by the client's reference-less API. */
interface ProjectPayments
{
    /** @return array<string, mixed>|null */
    public function subscription(string $organization, string $project): ?array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function perform(string $operation, string $organization, string $project, array $input, string $idempotencyKey): array;
}
