<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * "Sudo mode" — a short-lived elevated-confirmation window requiring the user to
 * re-enter their password before a sensitive action (changing a password,
 * disabling MFA, regenerating recovery codes, removing a passkey, privileged admin
 * changes). This is step-up re-authentication (OWASP ASVS V2; the OAuth analogue
 * is RFC 9470): possession of an active session isn't enough for the crown jewels.
 */
final class Sudo
{
    private const SESSION_KEY = 'cbox.sudo_confirmed_at';

    /** How long a sudo confirmation stays valid, in seconds. */
    public const WINDOW = 900;

    public function confirmed(): bool
    {
        $at = session()->get(self::SESSION_KEY);

        return is_int($at) && (time() - $at) < self::WINDOW;
    }

    public function confirm(): void
    {
        session()->put(self::SESSION_KEY, time());
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
