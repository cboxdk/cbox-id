<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\TargetEnvironment;
use App\Platform\Console\ConsoleScope;

/**
 * Which environment an operator has pointed the console at. A PREFERENCE, not a
 * credential.
 *
 * This file was `OperatorAuth`, and it authenticated: `attempt()`, `establish()`,
 * `check()`, `current()`, a pending-second-factor marker and a session key of its own,
 * verified against `platform_operators` — a table that held an email and a bcrypt hash
 * and nothing else. Everything that protects a sign-in on this platform (password
 * policy, breached-password refusal, lockout, TOTP, passkeys, step-up, session
 * revocation) lives on the SUBJECT, and the operator had none of it. The widest reach
 * in the product sat behind the weakest door, and it was weakest BECAUSE it was
 * separate.
 *
 * The operator is a subject now, so there is one sign-in and one session, and
 * "does this person run the deployment?" is asked of it — {@see ConsoleScope::operator()},
 * the single place in the app that answers it. Nothing here answers it, deliberately:
 * a second answer is a second thing to keep in step.
 *
 * What survived is the one piece of operator session state that was never a credential.
 * The operator console is a single cross-environment URL, so it has to remember which
 * plane its reads are aimed at. That key is DISTINCT from any end-user environment
 * resolution: an operator re-targeting the console must never move the environment an
 * end user is served from.
 *
 * WHO may pin, and WHEN the pin is honoured, are not this class's business — it holds
 * the value. Writes come from operator-gated routes; the read happens in
 * {@see TargetEnvironment}, which runs only after operator authority is established.
 */
final class OperatorEnvironment
{
    /**
     * Unchanged from `OperatorAuth::ENV_KEY`. The name moved; the key did not, so an
     * operator holding a live session across the deploy keeps the plane they targeted.
     */
    public const SESSION_KEY = 'cbox.operator_environment';

    /** The targeted environment slug, or null when the console follows the host. */
    public function slug(): ?string
    {
        $slug = session()->get(self::SESSION_KEY);

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Aim the console at a plane. The caller has resolved a REAL environment first. */
    public function pointAt(string $slug): void
    {
        session()->put(self::SESSION_KEY, $slug);
    }

    /** Stop targeting a plane — the console falls back to the request host. */
    public function release(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
