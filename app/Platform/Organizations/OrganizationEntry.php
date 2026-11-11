<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

/** One organization as `/orgs` lists it: the platform-root id, its address and name, and the person's role. */
final readonly class OrganizationEntry
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $role,
    ) {}
}
