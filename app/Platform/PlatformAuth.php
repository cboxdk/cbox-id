<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Otp\Contracts\OtpService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;

/**
 * Bridges the framework's session store to the browser. A logged-in browser
 * holds only the platform session id in Laravel's encrypted, http-only session
 * cookie — never a token. The framework session stays the source of truth
 * (revocable, records how the user authenticated).
 */
final class PlatformAuth
{
    public const SESSION_KEY = 'cbox.session';

    public const ORG_KEY = 'cbox.org';

    private const MFA_PENDING_KEY = 'cbox.mfa_pending';

    private const OTP_PENDING_KEY = 'cbox.otp_pending';

    private const OTP_PURPOSE = 'login_step_up';

    private const PENDING_LINK_KEY = 'cbox.pending_link';

    /** A precomputed hash so a login for a non-existent user still does the work. */
    private static ?string $timingHash = null;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Subjects $subjects,
        private readonly Memberships $memberships,
        private readonly Mfa $mfa,
        private readonly OtpService $otp,
        private readonly Hasher $hasher,
    ) {}

    /**
     * Attempt a password login. Returns 'ok', 'mfa', 'otp', or 'invalid'. On 'mfa'
     * and 'otp' the subject is held pending a second factor — no session yet.
     *
     * `$requireStepUp` (an elevated risk assessment) forces an additional factor even
     * when the account would otherwise sign in on password alone: if the account has
     * TOTP it goes through the normal MFA challenge; if it has no second factor we
     * step up with an emailed one-time code (possession of the inbox) rather than let
     * a risky sign-in through — and rather than locking the account out.
     */
    public function attemptPassword(Request $request, string $email, string $password, bool $requireStepUp = false): string
    {
        $subject = $this->subjects->findByEmail($email);

        if ($subject === null) {
            // Do equivalent hashing work so a missing account isn't measurably
            // faster than a wrong password — closes the email-enumeration oracle.
            $this->hasher->check($password, self::timingHash());

            return 'invalid';
        }

        if (! $this->subjects->verifyPassword($subject->id, $password)) {
            return 'invalid';
        }

        if ($this->mfa->hasConfirmedTotp($subject->id)) {
            session()->put(self::MFA_PENDING_KEY, $subject->id);

            return 'mfa';
        }

        if ($requireStepUp) {
            $this->otp->issue(self::OTP_PURPOSE, $email, 'email', $request->ip());
            session()->put(self::OTP_PENDING_KEY, ['subject' => $subject->id, 'email' => $email]);

            return 'otp';
        }

        $this->establish($request, $subject->id, ['pwd']);

        return 'ok';
    }

    /**
     * The subject + email held pending an emailed step-up code, or null.
     *
     * @return array{subject: string, email: string}|null
     */
    public function pendingOtpStepUp(Request $request): ?array
    {
        $pending = session()->get(self::OTP_PENDING_KEY);

        if (! is_array($pending) || ! is_string($pending['subject'] ?? null) || ! is_string($pending['email'] ?? null)) {
            return null;
        }

        return ['subject' => $pending['subject'], 'email' => $pending['email']];
    }

    /**
     * Complete a risk step-up with the emailed one-time code. The resulting session's
     * amr records 'otp', so it is treated as a two-factor (aal2) login downstream.
     */
    public function completeOtpStepUp(Request $request, string $code): bool
    {
        $pending = $this->pendingOtpStepUp($request);

        if ($pending === null || ! $this->otp->verifyLatest(self::OTP_PURPOSE, $pending['email'], $code, $request->ip())->verified) {
            return false;
        }

        session()->forget(self::OTP_PENDING_KEY);
        $this->establish($request, $pending['subject'], ['pwd', 'otp']);

        return true;
    }

    private function timingHash(): string
    {
        return self::$timingHash ??= $this->hasher->make('cbox-id-timing-equalizer');
    }

    public function pendingMfaSubject(Request $request): ?string
    {
        $id = session()->get(self::MFA_PENDING_KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * Complete a pending MFA challenge with a TOTP code.
     */
    public function completeMfa(Request $request, string $code): bool
    {
        $subjectId = $this->pendingMfaSubject($request);

        if ($subjectId === null || ! $this->mfa->verifyTotp($subjectId, $code)) {
            return false;
        }

        session()->forget(self::MFA_PENDING_KEY);
        $this->establish($request, $subjectId, ['pwd', 'mfa']);

        return true;
    }

    /**
     * Complete the challenge with a one-time recovery code instead of a TOTP code
     * — the escape hatch when the authenticator is unavailable. A recovery code is
     * still a second factor, so the resulting session's amr records 'mfa'.
     */
    public function completeMfaWithRecoveryCode(Request $request, string $code): bool
    {
        $subjectId = $this->pendingMfaSubject($request);

        if ($subjectId === null || ! $this->mfa->verifyRecoveryCode($subjectId, $code)) {
            return false;
        }

        session()->forget(self::MFA_PENDING_KEY);
        $this->establish($request, $subjectId, ['pwd', 'mfa']);

        return true;
    }

    /**
     * Establish a browser session for an already-authenticated subject (also
     * used after magic-link and passkey login).
     *
     * @param  list<string>  $amr  how the subject authenticated
     */
    public function establish(Request $request, string $subjectId, array $amr): void
    {
        $organizationId = $this->memberships->forUser($subjectId)->first()?->organization_id;

        // Record the request IP + user-agent on the session so adaptive-risk signals
        // (new device, geo-velocity) have a history to compare future logins against.
        $session = $this->sessions->start($subjectId, $organizationId, $amr, $request->ip(), $request->userAgent());

        session()->put(self::SESSION_KEY, $session->id);

        if ($organizationId !== null) {
            session()->put(self::ORG_KEY, $organizationId);
        }

        // Rotate the id to defeat session fixation.
        session()->regenerate();

        $this->applyPendingLink($subjectId);
    }

    /**
     * Adopt a framework session that was already started (e.g. by magic-link
     * redemption) into the browser cookie.
     */
    public function adopt(Request $request, Session $session): void
    {
        session()->put(self::SESSION_KEY, $session->id);

        $organizationId = $session->organization_id
            ?? $this->memberships->forUser($session->user_id)->value('organization_id');

        if (is_string($organizationId)) {
            session()->put(self::ORG_KEY, $organizationId);
        }

        session()->regenerate();

        $this->applyPendingLink($session->user_id);
    }

    /**
     * Hold a verified social identity aside while the user proves control of the
     * existing account by signing in. Linking then completes automatically.
     */
    public function startPendingLink(FederatedPrincipal $principal): void
    {
        session()->put(self::PENDING_LINK_KEY, [
            'provider' => $principal->provider,
            'subject' => $principal->subject,
            'email' => $principal->email,
            'name' => $principal->name,
            'raw' => $principal->raw,
        ]);
    }

    /**
     * The human label of a provider awaiting linking (e.g. "Google"), or null.
     */
    public function pendingLinkLabel(): ?string
    {
        $pending = session()->get(self::PENDING_LINK_KEY);
        $provider = is_array($pending) && is_string($pending['provider'] ?? null) ? $pending['provider'] : null;

        return $provider === null ? null : SocialProviders::label(str_replace('social:', '', $provider));
    }

    private function applyPendingLink(string $subjectId): void
    {
        $pending = session()->pull(self::PENDING_LINK_KEY);

        if (! is_array($pending) || ! is_string($pending['provider'] ?? null) || ! is_string($pending['subject'] ?? null)) {
            return;
        }

        // Only auto-complete the link when the held identity's verified email
        // matches the account that just authenticated. Otherwise the pending
        // identity belongs to someone else, and stapling it onto whoever signs in
        // next would let an attacker attach their provider account to a victim who
        // happens to log in afterwards. On a mismatch we discard it (already
        // pulled) rather than link.
        $pendingEmail = is_string($pending['email'] ?? null) ? $pending['email'] : null;
        $subjectEmail = $this->subjects->find($subjectId)?->email;

        if ($pendingEmail === null
            || $subjectEmail === null
            || ! hash_equals(mb_strtolower($subjectEmail), mb_strtolower($pendingEmail))) {
            return;
        }

        $raw = $pending['raw'] ?? null;

        try {
            $this->subjects->link($subjectId, new FederatedPrincipal(
                provider: $pending['provider'],
                subject: $pending['subject'],
                email: $pendingEmail,
                name: is_string($pending['name'] ?? null) ? $pending['name'] : null,
                raw: is_array($raw) ? array_filter($raw, 'is_string', ARRAY_FILTER_USE_KEY) : [],
            ));
        } catch (IdentityAlreadyLinked) {
            // The identity is already attached elsewhere — nothing to do.
        }
    }

    public function switchOrganization(Request $request, string $organizationId): void
    {
        session()->put(self::ORG_KEY, $organizationId);
    }

    public function logout(Request $request): void
    {
        $sessionId = session()->get(self::SESSION_KEY);

        if (is_string($sessionId)) {
            $this->sessions->revoke($sessionId);
        }

        session()->forget([self::SESSION_KEY, self::ORG_KEY, self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, self::PENDING_LINK_KEY]);
        session()->invalidate();
        session()->regenerateToken();
    }
}
