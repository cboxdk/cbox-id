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

    /** What the operator is told, in their language. */
    public function message(): string
    {
        return match ($this) {
            self::Admitted => '',
            self::NoPasskey => __('console.operator_standing_no_passkey', ['command' => 'php artisan cbox-id:operator:passkey-link <your email>']),
            self::NeedsPasskey => __('console.operator_standing_needs_passkey'),
            self::Expired => __('console.operator_standing_expired'),
            self::Idle => __('console.operator_standing_idle'),
            self::Unverifiable => __('console.operator_standing_unverifiable'),
        };
    }

    /** Whether a passkey ceremony can fix it. */
    public function confirmable(): bool
    {
        return $this === self::NeedsPasskey || $this === self::Expired || $this === self::Idle;
    }
}
