<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\StepUpPlane;

/**
 * What the console asks the login host for beyond "sign this person in": how fresh the
 * authentication must be, the authentication context it must reach, and the step-up plane
 * the answer opens, if it is for one.
 */
final readonly class ConsoleStepUpRequest
{
    public function __construct(
        /** The plane whose window the answer opens, or null when no window is opened. */
        public ?StepUpPlane $plane = null,
        public ?int $maxAge = null,
        public ?string $acr = null,
        public ?string $environment = null,
        /**
         * The organization to be signed in to (`organization=` on the authorize request).
         * The login host sends the person through that organization's own identity
         * provider when it requires one.
         */
        public ?string $organization = null,
    ) {}
}
