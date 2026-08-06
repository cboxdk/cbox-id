<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The organization whose SSO mandate just refused a password, and the door it left open.
 *
 * A value rather than a pair of view variables: the two sign-in screens render the same
 * refusal, and "the name" and "the URL" travelling separately is how one of them would
 * eventually render a name with somebody else's link. `startUrl` is deliberately nullable
 * — an organization can mandate SSO and have no active connection yet, which is a real
 * state an administrator can create in the console, and the screen has to say something
 * true about it rather than link to nothing.
 */
final readonly class SsoMandate
{
    public function __construct(
        public string $organizationName,
        public ?string $startUrl,
    ) {}
}
