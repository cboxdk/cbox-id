<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * "Sudo mode" for the account-member (workspace) plane — a short-lived
 * elevated-confirmation window requiring the member to re-enter their password
 * before a sensitive action (minting an account/environment API key, enrolling a
 * passkey, regenerating MFA recovery codes). Step-up re-authentication (OWASP ASVS
 * V2; OAuth analogue RFC 9470): a hijacked-but-stale workspace session must not be
 * able to plant durable new credentials.
 *
 * Deliberately a SEPARATE session key from the subject-plane {@see Sudo}: a
 * confirmation on one plane must never satisfy a step-up on the other.
 */
final class WorkspaceSudo
{
    public const SESSION_KEY = 'cbox.account_sudo_confirmed_at';

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
