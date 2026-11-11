<?php

declare(strict_types=1);

namespace App\Platform\Operators;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\OAuthServer\Enums\AuthMethod;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * THE OPERATOR PLANE'S PASSKEY RULE — whether the session in hand has proved, recently
 * enough, that its person holds a passkey or security key.
 *
 * WHY THE PLANE NEEDS ITS OWN RULE. An operator is an ordinary subject of the platform
 * root, signed in through the one door everybody uses, and that door counts a time-based
 * code as a second factor. A code can be read out to a look-alike page; a WebAuthn
 * assertion cannot, because the browser binds it to the origin. `/admin` and the root
 * environment's console reach every customer, so they ask for the second kind.
 *
 * WHAT IS KEPT. One record in the browser session: which sign-in session it belongs to,
 * when a passkey was last used (`at`) and when the plane was last used (`seen`). It starts
 * from the sign-in itself when that was a passkey, and is rewritten by a passkey
 * confirmation ({@see self::confirm()}). Bound to the sign-in session's id, so an account
 * switch, a new sign-in or an impersonation never inherits it.
 *
 * WHAT IT ANSWERS. {@see self::standing()}: may this session use the plane at all (a
 * passkey proof no older than the absolute limit, the plane used within the idle limit).
 * {@see self::fresh()}: is the proof recent enough for a dangerous act.
 *
 * Everything here is off unless `cbox-id.operators.phishing_resistant` is on.
 *
 * A HOST THAT SIGNS THE CONSOLE IN THROUGH ANOTHER ORIGIN (the console as a relying party
 * of a separate login host) cannot run the ceremony here, because a passkey is bound to
 * the origin it was made on. That flow calls {@see self::confirm()} from its callback once
 * the id token says `urn:cbox-id:phr`, with the token's `auth_time`.
 */
final class OperatorAssurance
{
    public const string SESSION_KEY = 'cbox.operator_assurance';

    /** Set on the request by the `/admin` gate: this request is on the operator plane. */
    public const string PLANE_ATTRIBUTE = 'cbox.operator_plane';

    private ?string $memoSessionId = null;

    private ?Session $memoSession = null;

    public function __construct(
        private readonly PlatformRoot $root,
        private readonly SessionManager $sessions,
        private readonly EnvironmentAdminAuth $environmentAdmin,
        private readonly Surfaces $surfaces,
    ) {}

    public function enabled(): bool
    {
        return config('cbox-id.operators.phishing_resistant', false) === true;
    }

    /**
     * Whether this request is one the rule governs: on `/admin`, or administering the
     * platform root environment's own console (whose administrators are the operators).
     */
    public function governs(?Request $request = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return ($request ?? request())->attributes->get(self::PLANE_ATTRIBUTE) === true
            || $this->environmentAdmin->rootOperator() !== null;
    }

    /**
     * Whether these sign-in methods are a passkey with user verification.
     *
     * The framework's own predicate where it has one; the same rule spelled here for a
     * framework release that predates it, so the plane never falls back to something weaker.
     *
     * @param  list<string>  $amr
     */
    public function reached(array $amr): bool
    {
        if (! $this->verifiesUsers()) {
            return false;
        }

        // By name, because the case exists only from the framework release that adds it.
        $case = AuthenticationContextClass::class.'::PhishingResistant';
        $class = defined($case) ? constant($case) : null;

        return $class instanceof AuthenticationContextClass
            ? $class->isSatisfiedBy($amr)
            : in_array(AuthMethod::Passkey->value, $amr, true) && in_array(AuthMethod::MultiFactor->value, $amr, true);
    }

    /**
     * Whether the passkey can be confirmed on THIS host.
     *
     * Not in login-host mode: a passkey belongs to the sign-in host's origin, and the
     * console is on another. The console's own sign-in through that host then carries the
     * proof ({@see self::confirm()}), and until it does the operator signs in again with
     * the passkey.
     */
    public function confirmsHere(): bool
    {
        return ! $this->surfaces->split();
    }

    /** What an operator is told where {@see self::confirmsHere()} is false. */
    public function elsewhereMessage(): string
    {
        return 'Platform administration requires a passkey or security key, and passkeys are confirmed on the sign-in host ('.$this->surfaces->loginHost().'). Sign out, then sign in again with your passkey.';
    }

    /** Whether this deployment's passkey doors require the authenticator to verify the person. */
    public function verifiesUsers(): bool
    {
        return config('cbox-id.webauthn.user_verification', true) !== false;
    }

    public function hasPasskey(string $subjectId): bool
    {
        return $this->root->run(
            static fn (): bool => WebAuthnCredential::query()->where('user_id', $subjectId)->exists(),
        ) === true;
    }

    /** The person behind the sign-in session in hand, or null. */
    public function subjectId(): ?string
    {
        return $this->session()?->user_id;
    }

    public function standing(): OperatorStanding
    {
        $session = $this->session();

        if ($session === null) {
            return OperatorStanding::NeedsPasskey;
        }

        if (! $this->verifiesUsers()) {
            return OperatorStanding::Unverifiable;
        }

        if (! $this->hasPasskey($session->user_id)) {
            return OperatorStanding::NoPasskey;
        }

        $proof = $this->proof($session);

        if ($proof === null) {
            return OperatorStanding::NeedsPasskey;
        }

        $now = now()->getTimestamp();

        if (($now - $proof['at']) > $this->minutes('absolute_minutes', 60) * 60) {
            return OperatorStanding::Expired;
        }

        if (($now - $proof['seen']) > $this->minutes('idle_minutes', 15) * 60) {
            return OperatorStanding::Idle;
        }

        return OperatorStanding::Admitted;
    }

    /** Whether the passkey proof is recent enough for a dangerous act. */
    public function fresh(): bool
    {
        $session = $this->session();

        if ($session === null || $this->standing() !== OperatorStanding::Admitted) {
            return false;
        }

        $proof = $this->proof($session);
        $configured = config('cbox-id.operators.reconfirm_seconds', 300);
        $window = max(30, is_numeric($configured) ? (int) $configured : 300);

        return $proof !== null && (now()->getTimestamp() - $proof['at']) <= $window;
    }

    /**
     * A passkey was just verified for this session's person.
     *
     * `$at` is when, for a caller that learned it from somewhere else (an id token's
     * `auth_time`); a time in the future is read as now.
     */
    public function confirm(?int $at = null): void
    {
        $session = $this->session();

        if ($session === null) {
            return;
        }

        $now = now()->getTimestamp();

        session()->put(self::SESSION_KEY, [
            'session' => $session->id,
            'at' => $at === null ? $now : min($at, $now),
            'seen' => $now,
        ]);
    }

    /** The plane was used: the idle clock starts again. Only for a session that is admitted. */
    public function touch(): void
    {
        $session = $this->session();
        $proof = $session === null ? null : $this->proof($session);

        if ($session === null || $proof === null) {
            return;
        }

        session()->put(self::SESSION_KEY, [...$proof, 'seen' => now()->getTimestamp()]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The proof held for this sign-in session: the stored one when it belongs to it,
     * otherwise the sign-in itself when that was a passkey.
     *
     * @return array{session: string, at: int, seen: int}|null
     */
    private function proof(Session $session): ?array
    {
        $held = session()->get(self::SESSION_KEY);

        if (is_array($held)
            && ($held['session'] ?? null) === $session->id
            && is_int($held['at'] ?? null)
            && is_int($held['seen'] ?? null)) {
            return ['session' => $session->id, 'at' => $held['at'], 'seen' => $held['seen']];
        }

        $amr = array_values(array_filter((array) $session->amr, is_string(...)));
        $signedInAt = $session->created_at?->getTimestamp();

        if ($signedInAt === null || ! $this->reached($amr)) {
            return null;
        }

        return ['session' => $session->id, 'at' => $signedInAt, 'seen' => $signedInAt];
    }

    /** The live sign-in session this browser holds, read in the platform root. */
    private function session(): ?Session
    {
        $sessionId = session()->get(PlatformAuth::SESSION_KEY);

        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        if ($this->memoSessionId === $sessionId) {
            return $this->memoSession;
        }

        $session = $this->root->run(fn (): ?Session => $this->sessions->active($sessionId));

        $this->memoSessionId = $sessionId;

        return $this->memoSession = $session instanceof Session ? $session : null;
    }

    private function minutes(string $key, int $default): int
    {
        $configured = config('cbox-id.operators.session.'.$key, $default);

        return max(1, is_numeric($configured) ? (int) $configured : $default);
    }
}
