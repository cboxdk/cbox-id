<?php

declare(strict_types=1);

namespace App\ServiceConnections\Contracts;

use Cbox\Id\OAuthServer\ValueObjects\Introspection;

interface ReadsConnectionEvents
{
    /** @param list<string> $types
     * @return array<string, mixed>
     */
    public function page(Introspection $caller, string $grant, array $types, ?string $after, int $limit): array;
}
