<?php

declare(strict_types=1);

namespace App\Platform\Staff\ValueObjects;

use Cbox\Id\AccessControl\Enums\GrantSource;

/**
 * One person holding one staff role, environment-wide.
 */
readonly class StaffGrant
{
    public function __construct(
        public string $userId,
        /** Null when the person no longer resolves in this environment. */
        public ?string $userName,
        public ?string $userEmail,
        public StaffRole $role,
        public GrantSource $source,
    ) {}
}
