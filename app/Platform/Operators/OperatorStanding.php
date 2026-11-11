<?php

declare(strict_types=1);

namespace App\Platform\Operators;

/**
 * Where an operator's session stands against the operator plane's passkey rule
 * ({@see OperatorAssurance}).
 */
enum OperatorStanding
{
    /** A passkey proof is in hand and inside both time limits. */
    case Admitted;

    /** The operator holds no passkey or security key: they must enrol one first. */
    case NoPasskey;

    /** Signed in another way (a password and a code, single sign-on): confirm with the passkey. */
    case NeedsPasskey;

    /** The passkey proof is older than the plane allows. */
    case Expired;

    /** Nothing was done on the plane for longer than it allows. */
    case Idle;

    /** This deployment does not ask authenticators to verify the person, so nothing can prove it. */
    case Unverifiable;

    /** What the operator is told. */
    public function message(): string
    {
        return match ($this) {
            self::Admitted => '',
            self::NoPasskey => 'Platform administration requires a passkey or security key, and your account has none. Add one under My account → Security, then come back. If you cannot sign in at all, ask somebody with shell access to run: php artisan cbox-id:operator:passkey-link <your email>',
            self::NeedsPasskey => 'Platform administration requires a passkey or security key. Confirm with yours to continue.',
            self::Expired => 'Your passkey confirmation for platform administration has expired. Confirm with your passkey again.',
            self::Idle => 'Platform administration was idle for a while. Confirm with your passkey to continue.',
            self::Unverifiable => 'Platform administration requires a passkey with user verification, and this deployment has it turned off (cbox-id.webauthn.user_verification). Turn it on, or turn off cbox-id.operators.phishing_resistant.',
        };
    }

    /** Whether a passkey ceremony can fix it. */
    public function confirmable(): bool
    {
        return $this === self::NeedsPasskey || $this === self::Expired || $this === self::Idle;
    }
}
