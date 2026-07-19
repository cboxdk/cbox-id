<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Http\Request;

/**
 * Session bridge for platform operators — the identity above every environment.
 * Deliberately separate from {@see PlatformAuth} (org-scoped users): an operator
 * console is its own world, so an operator session never mixes with a user
 * session. The operator is not environment-owned, so no environment is pinned at
 * login; the operator selects a target plane inside the console.
 */
final class OperatorAuth
{
    public const SESSION_KEY = 'cbox.operator';

    /**
     * The environment the operator has pointed the console at. Deliberately
     * DISTINCT from the end-user environment resolution: an operator switching
     * planes must never move the environment an end user is served, so the two
     * never share a session key.
     */
    public const ENV_KEY = 'cbox.operator_environment';

    /**
     * A password verified but a confirmed TOTP factor is still outstanding. Holds
     * only the operator id, and — deliberately distinct from {@see SESSION_KEY} —
     * grants NO console access on its own: the gate still requires a full session.
     */
    public const PENDING_KEY = 'cbox.operator_pending';

    public function __construct(
        private readonly PlatformOperators $operators,
        private readonly OperatorMfa $mfa,
    ) {}

    /**
     * Verify credentials. Returns:
     *  - 'invalid' for a wrong password or a suspended operator (never authenticates),
     *  - 'mfa'     when the password is right but a confirmed TOTP factor is required
     *              — no session is started; a short-lived pending marker is stashed,
     *  - 'ok'      when the password is right and no second factor is required — the
     *              full session is established immediately, exactly as before.
     */
    public function attempt(Request $request, string $email, string $password): AttemptOutcome
    {
        $operator = $this->operators->findByEmail($email);

        if ($operator === null || ! $this->operators->verifyPassword($operator->id, $password)) {
            return AttemptOutcome::Invalid;
        }

        if ($this->mfa->hasConfirmedTotp($operator->id)) {
            session()->put(self::PENDING_KEY, $operator->id);

            return AttemptOutcome::Mfa;
        }

        $this->establish($operator->id);

        return AttemptOutcome::Ok;
    }

    /**
     * Establish the full operator session for an already-authenticated operator —
     * the single place session state is created, shared by the no-MFA login path
     * and the post-challenge path so they can never diverge.
     */
    public function establish(string $operatorId): void
    {
        $this->operators->touchLogin($operatorId);

        session()->forget(self::PENDING_KEY);
        session()->put(self::SESSION_KEY, $operatorId);
        session()->regenerate();
    }

    /**
     * The operator id held pending a second factor, or null. Never grants access —
     * only {@see current()} (which requires {@see SESSION_KEY} + active status) does.
     */
    public function pendingOperatorId(): ?string
    {
        $id = session()->get(self::PENDING_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    public function id(): ?string
    {
        $id = session()->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function current(): ?PlatformOperator
    {
        $id = $this->id();

        // A suspended-after-login operator loses access immediately: re-check
        // status on every resolve, not just at sign-in.
        $operator = $id !== null ? $this->operators->find($id) : null;

        return $operator !== null && $operator->isActive() ? $operator : null;
    }

    public function logout(Request $request): void
    {
        session()->forget([self::SESSION_KEY, self::ENV_KEY, self::PENDING_KEY]);
        // invalidate() (not regenerate()) so no operator session data survives the
        // logout, matching PlatformAuth::logout.
        session()->invalidate();
        session()->regenerateToken();
    }
}
