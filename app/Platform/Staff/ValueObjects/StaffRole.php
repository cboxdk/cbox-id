<?php

declare(strict_types=1);

namespace App\Platform\Staff\ValueObjects;

/**
 * A role that may be granted across the whole environment.
 */
readonly class StaffRole
{
    public function __construct(
        public string $id,
        public string $name,
        /** The app whose tokens it reaches, or null for every app. */
        public ?string $clientId,
        /** That app's name, or null for every app. */
        public ?string $appName,
        /**
         * False for a staff-only role: one no organization's administrators may grant.
         * Every role here can be granted everywhere; only these can be granted ONLY here.
         */
        public bool $tenantAssignable,
    ) {}
}
