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
    /**
     * Public so the session-transition paths can forget it.
     *
     * A step-up confirms "the person at this keyboard is still who this session says",
     * and every transition below breaks that premise: establishing a session, adopting
     * one, switching organization, and entering or leaving an impersonation. Laravel's
     * session regenerate() rotates the id and PRESERVES the data, so a confirmation made
     * before any of those survived it.
     */
    public const SESSION_KEY = 'cbox.sudo_confirmed_at';

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
