<?php

declare(strict_types=1);

namespace App\Platform;

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

    public function __construct(private readonly PlatformOperators $operators) {}

    /**
     * Verify credentials and, on success, establish an operator session.
     * Returns false for a wrong password or a suspended operator.
     */
    public function attempt(Request $request, string $email, string $password): bool
    {
        $operator = $this->operators->findByEmail($email);

        if ($operator === null || ! $this->operators->verifyPassword($operator->id, $password)) {
            return false;
        }

        $this->operators->touchLogin($operator->id);

        session()->put(self::SESSION_KEY, $operator->id);
        session()->regenerate();

        return true;
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
        session()->forget([self::SESSION_KEY, self::ENV_KEY]);
        // invalidate() (not regenerate()) so no operator session data survives the
        // logout, matching PlatformAuth::logout.
        session()->invalidate();
        session()->regenerateToken();
    }
}
