<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * "Sudo mode" for the ENVIRONMENT control plane — a short-lived elevated-confirmation
 * window requiring the administrator to re-enter their password before a sensitive
 * action. Step-up re-authentication (OWASP ASVS V2; the OAuth analogue is RFC 9470):
 * possession of a live session is not enough for the crown jewels.
 *
 * WHY A THIRD ONE. There was no environment-plane step-up at all — only {@see Sudo} for
 * the organization plane and {@see WorkspaceSudo} for the account plane. The token vault
 * made the gap visible: the same rotate, grant and revoke actions demanded a fresh
 * password on the organization plane and none whatsoever on the environment plane, where
 * the administrator can act on EVERY organization in the environment. A hijacked or
 * unattended env-admin session could plant a grant to an attacker-controlled client id
 * and lease a tenant's production API key, with no re-authentication anywhere in the path.
 *
 * A SEPARATE session key from both siblings, deliberately: a confirmation made on one
 * plane must never satisfy a step-up on another. The planes answer to different
 * authorities — an organization member, an account member, an environment administrator —
 * and a shared key would let the weakest of the three unlock the strongest.
 */
final class EnvironmentSudo
{
    /**
     * Public so the session-transition paths can forget it, the same way {@see Sudo}'s is.
     *
     * A step-up confirms "the person at this keyboard is still who this session says", and
     * establishing or adopting a session breaks that premise. Laravel's session
     * regenerate() rotates the id and PRESERVES the data, so a confirmation made before a
     * transition would otherwise survive it.
     */
    public const SESSION_KEY = 'cbox.env_sudo_confirmed_at';

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
