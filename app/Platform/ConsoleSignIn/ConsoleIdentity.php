<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

/**
 * What a verified ID token from the login host says: who, from which SSO session, and how
 * and when they authenticated.
 */
final readonly class ConsoleIdentity
{
    /**
     * @param  list<string>  $amr
     */
    public function __construct(
        public string $subjectId,
        public ?string $sid,
        public ?int $authTime,
        public ?string $acr,
        public array $amr,
        /** The organization the sign-in is in (`org`), when the login host named one. */
        public ?string $organizationId = null,
    ) {}
}
