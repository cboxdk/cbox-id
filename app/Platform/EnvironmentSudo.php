<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * "Sudo mode" for the ENVIRONMENT control plane — a short-lived elevated-confirmation
 * window requiring the administrator to re-enter their password before a sensitive
 * action. Step-up re-authentication (OWASP ASVS V2; the OAuth analogue is RFC 9470):
 * possession of a live session is not enough for the crown jewels.
 *
 * WHY A THIRD ONE. There was no environment-plane step-up at all — only {@see Sudo} for
 * the organization plane. The token vault
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

    /**
     * Whether a confirmation for THIS environment is still fresh.
     *
     * Bound to the environment it was made in. The console moved to the app host, where one
     * session administers every environment its person may reach; a confirmation made in a
     * sandbox must not open the production vault two tabs over.
     */
    public function confirmed(): bool
    {
        $held = session()->get(self::SESSION_KEY);

        return is_array($held)
            && is_int($held['at'] ?? null)
            && ($held['environment'] ?? null) === $this->environment()
            && $this->environment() !== null
            && (time() - $held['at']) < self::WINDOW;
    }

    /**
     * Confirm the step-up for an environment: the request's own unless named — a caller
     * running in the platform root names the console's. From now, or from `$at` when the
     * proof was given earlier (the `auth_time` of a re-authentication at the login host).
     */
    public function confirm(?string $environment = null, ?int $at = null): void
    {
        session()->put(self::SESSION_KEY, [
            'environment' => $environment ?? $this->environment(),
            'at' => $at === null ? time() : min($at, time()),
        ]);
    }

    private function environment(): ?string
    {
        return app(EnvironmentContext::class)->current()?->environmentKey();
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
