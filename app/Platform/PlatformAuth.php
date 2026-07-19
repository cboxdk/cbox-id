<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
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
    // The active account's framework session id + org. Kept as the single source
    // the auth middleware reads, so it always reflects whichever held account is
    // active — the rest of the app is unaware of multi-account.
    public const SESSION_KEY = 'cbox.session';

    public const ORG_KEY = 'cbox.org';

    // The set of concurrently signed-in accounts (Notion/Slack style): a map of
    // subjectId => {session, org}. SESSION_KEY/ORG_KEY are the active one, derived.
    public const ACCOUNTS_KEY = 'cbox.accounts';

    public const ACTIVE_KEY = 'cbox.active';

    // Bound the held set so the (cookie-backed) session can't grow without limit.
    private const MAX_ACCOUNTS = 8;

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
    public function attemptPassword(Request $request, string $email, string $password, bool $requireStepUp = false): AttemptOutcome
    {
        $subject = $this->subjects->findByEmail($email);

        if ($subject === null) {
            // Do equivalent hashing work so a missing account isn't measurably
            // faster than a wrong password — closes the email-enumeration oracle.
            $this->hasher->check($password, self::timingHash());

            return AttemptOutcome::Invalid;
        }

        if (! $this->subjects->verifyPassword($subject->id, $password)) {
            return AttemptOutcome::Invalid;
        }

        if ($this->mfa->hasConfirmedTotp($subject->id)) {
            session()->put(self::MFA_PENDING_KEY, $subject->id);

            return AttemptOutcome::Mfa;
        }

        if ($requireStepUp) {
            $this->otp->issue(self::OTP_PURPOSE, $email, 'email', $request->ip());
            session()->put(self::OTP_PENDING_KEY, ['subject' => $subject->id, 'email' => $email]);

            return AttemptOutcome::Otp;
        }

        $this->establish($request, $subject->id, ['pwd']);

        return AttemptOutcome::Ok;
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
     * Re-issue the step-up code to the pending recipient (the "resend" control).
     * Returns false when there is nothing pending; the underlying OTP issuance is
     * itself rate-limited, so this cannot be used to bomb an inbox.
     */
    public function resendOtpStepUp(Request $request): bool
    {
        $pending = $this->pendingOtpStepUp($request);

        if ($pending === null) {
            return false;
        }

        $this->otp->issue(self::OTP_PURPOSE, $pending['email'], 'email', $request->ip());

        return true;
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
     * @param  string|null  $organizationId  Pin the session to this org (e.g. the
     *                                       org an impersonation was authorized
     *                                       against); defaults to the subject's
     *                                       first membership for a normal sign-in.
     */
    public function establish(Request $request, string $subjectId, array $amr, ?string $organizationId = null): void
    {
        // A full session is being minted — drop any half-finished second-factor
        // handles so a completed sign-in can never leave a redeemable pending code
        // dangling in the (data-preserving) session.
        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY]);

        // Pin to the caller-supplied org when given (impersonation authorizes against
        // a SPECIFIC org — the session must land there, not in the subject's oldest
        // membership, or the role gate and the effective session would disagree).
        $organizationId ??= $this->memberships->forUser($subjectId)->first()?->organization_id;

        // Record the request IP + user-agent on the session so adaptive-risk signals
        // (new device, geo-velocity) have a history to compare future logins against.
        $session = $this->sessions->start($subjectId, $organizationId, $amr, $request->ip(), $request->userAgent());

        // Add (or refresh) this account and make it active, keeping any other
        // signed-in accounts — so a second sign-in adds a switchable account
        // rather than replacing the first.
        $this->addAccount($subjectId, $session->id, $organizationId);

        // Rotate the id to defeat session fixation (preserves the accounts set).
        session()->regenerate();

        $this->applyPendingLink($subjectId);
    }

    /**
     * Adopt a framework session that was already started (e.g. by magic-link
     * redemption) into the browser cookie.
     */
    public function adopt(Request $request, Session $session): void
    {
        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY]);

        $organizationId = $session->organization_id
            ?? $this->memberships->forUser($session->user_id)->value('organization_id');

        $this->addAccount($session->user_id, $session->id, is_string($organizationId) ? $organizationId : null);

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

        // Keep the active account's remembered org in sync, so switching accounts
        // and back restores the org the user was in.
        $accounts = $this->accountsMap();
        $active = session()->get(self::ACTIVE_KEY);

        if (is_string($active) && isset($accounts[$active])) {
            $accounts[$active]['org'] = $organizationId;
            session()->put(self::ACCOUNTS_KEY, $accounts);
        }
    }

    /**
     * The concurrently signed-in accounts, resolved for display, newest last, with
     * the active one flagged. Sessions revoked/expired out from under us are pruned.
     *
     * @return list<array{subject_id: string, name: string, email: ?string, organization_id: ?string, active: bool}>
     */
    public function accounts(): array
    {
        $accounts = $this->accountsMap();
        $active = session()->get(self::ACTIVE_KEY);
        $out = [];
        $changed = false;

        foreach ($accounts as $subjectId => $entry) {
            $subject = $this->sessions->active($entry['session']) !== null
                ? $this->subjects->find($subjectId)
                : null;

            if ($subject === null) {
                // Session gone or subject removed — drop it from the held set.
                unset($accounts[$subjectId]);
                $changed = true;

                continue;
            }

            $out[] = [
                'subject_id' => $subjectId,
                'name' => $subject->name ?? $subject->email ?? $subjectId,
                'email' => $subject->email,
                'organization_id' => $entry['org'],
                'active' => $subjectId === $active,
            ];
        }

        if ($changed) {
            session()->put(self::ACCOUNTS_KEY, $accounts);
        }

        return $out;
    }

    /**
     * Switch the active account to another already-signed-in one — no re-auth. The
     * target session is re-validated (still active) before we activate it; a stale
     * entry is pruned and refused. Returns false when the account isn't held/valid.
     */
    public function switchTo(Request $request, string $subjectId): bool
    {
        $accounts = $this->accountsMap();

        if (! isset($accounts[$subjectId])) {
            return false;
        }

        $sessionId = $accounts[$subjectId]['session'];

        if ($this->sessions->active($sessionId) === null) {
            unset($accounts[$subjectId]);
            session()->put(self::ACCOUNTS_KEY, $accounts);

            return false;
        }

        $this->makeActive($subjectId, $sessionId, $accounts[$subjectId]['org']);

        // Rotate the id on privilege change (the active identity just changed).
        session()->regenerate();

        return true;
    }

    /**
     * Log out the ACTIVE account. If other accounts remain signed in, activate the
     * next one (Notion-style); otherwise fully tear the browser session down.
     */
    public function logout(Request $request): void
    {
        $accounts = $this->accountsMap();
        $active = session()->get(self::ACTIVE_KEY);

        if (is_string($active) && isset($accounts[$active])) {
            $this->sessions->revoke($accounts[$active]['session']);
            unset($accounts[$active]);
        } else {
            // No tracked active account — revoke whatever the derived key points at.
            $sessionId = session()->get(self::SESSION_KEY);

            if (is_string($sessionId)) {
                $this->sessions->revoke($sessionId);
            }
        }

        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, self::PENDING_LINK_KEY]);

        if ($accounts !== []) {
            $next = array_key_first($accounts);
            session()->put(self::ACCOUNTS_KEY, $accounts);
            $this->makeActive($next, $accounts[$next]['session'], $accounts[$next]['org']);
            session()->regenerate();

            return;
        }

        $this->tearDown();
    }

    /**
     * Log out of every signed-in account and tear the browser session down.
     */
    public function logoutAll(Request $request): void
    {
        foreach ($this->accountsMap() as $entry) {
            $this->sessions->revoke($entry['session']);
        }

        $sessionId = session()->get(self::SESSION_KEY);

        if (is_string($sessionId)) {
            $this->sessions->revoke($sessionId);
        }

        $this->tearDown();
    }

    /**
     * Add (or refresh) an account in the held set and make it active. Evicts the
     * oldest when the cap is reached (revoking its framework session).
     */
    private function addAccount(string $subjectId, string $sessionId, ?string $organizationId): void
    {
        $accounts = $this->accountsMap();

        if (! isset($accounts[$subjectId]) && count($accounts) >= self::MAX_ACCOUNTS) {
            // Non-empty branch (count >= cap), so there is always an oldest key.
            $oldest = array_key_first($accounts);
            $this->sessions->revoke($accounts[$oldest]['session']);
            unset($accounts[$oldest]);
        }

        // Re-insert at the end so refresh keeps recency ordering.
        unset($accounts[$subjectId]);
        $accounts[$subjectId] = ['session' => $sessionId, 'org' => $organizationId];

        session()->put(self::ACCOUNTS_KEY, $accounts);
        $this->makeActive($subjectId, $sessionId, $organizationId);
    }

    private function makeActive(string $subjectId, string $sessionId, ?string $organizationId): void
    {
        session()->put(self::ACTIVE_KEY, $subjectId);
        session()->put(self::SESSION_KEY, $sessionId);

        if ($organizationId !== null) {
            session()->put(self::ORG_KEY, $organizationId);
        } else {
            session()->forget(self::ORG_KEY);
        }
    }

    /**
     * @return array<string, array{session: string, org: ?string}>
     */
    private function accountsMap(): array
    {
        $raw = session()->get(self::ACCOUNTS_KEY);

        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $subjectId => $entry) {
            if (is_string($subjectId) && is_array($entry) && is_string($entry['session'] ?? null)) {
                $org = $entry['org'] ?? null;
                $map[$subjectId] = ['session' => $entry['session'], 'org' => is_string($org) ? $org : null];
            }
        }

        return $map;
    }

    private function tearDown(): void
    {
        session()->forget([
            self::SESSION_KEY, self::ORG_KEY, self::ACCOUNTS_KEY, self::ACTIVE_KEY,
            self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, self::PENDING_LINK_KEY,
        ]);
        session()->invalidate();
        session()->regenerateToken();
    }
}
