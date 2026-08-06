<?php

declare(strict_types=1);

namespace App\Platform\Install;

use Cbox\Id\Platform\DatabasePlatformOperators;

/**
 * The human who will own the deployment: the first platform operator.
 *
 * The password travels as a value here and NOWHERE else — it is handed straight to
 * {@see DatabasePlatformOperators::create()}, which pairs the operator with an ordinary
 * subject in the platform root, so the credential of record is the subject's and the
 * whole identity stack (password policy, breach refusal, lockout, TOTP, passkeys)
 * applies to the widest-reaching account in the product.
 *
 * `generated` travels with it because the installer must never invent a credential
 * silently: a password it made up is shown exactly once, and one it was given is never
 * echoed at all.
 */
final readonly class OperatorIdentity
{
    public function __construct(
        public string $email,
        public string $name,
        public string $password,
        public bool $generated = false,
    ) {}
}
