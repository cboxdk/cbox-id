<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Migration\LegacyMigration;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Otp\Contracts\OtpService;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Throwable;

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
        private readonly AdminPasswords $adminPasswords,
        private readonly AuthPolicies $policies,
        private readonly LoginAttempts $loginAttempts,
        private readonly PlatformRoot $platformRoot,
        private readonly OrganizationActivity $activity,
        // Optional: null unless a host has bound a LegacyCredentialSource. Nullable in the
        // constructor rather than resolved on demand, so the absence is visible in the
        // signature of the class that decides logins instead of buried in a container call.
        private readonly ?LegacyMigration $legacy = null,
    ) {}

    /**
     * Write the organization chain's sign-in entry, if this subject belongs to one.
     *
     * IT USED TO LIVE ON THE OTHER DOOR. `AccountAuth::establish()` recorded it, and every
     * console sign-in reached that method — until the account door was retired and `/login`
     * became the only one. After that, that method was reachable from exactly two flows,
     * signup and an accepted invitation, so the activity log held the two entries a member
     * generates while joining and nothing for any visit afterwards. The page still said
     * "Sign-ins" and still listed rows, which is the worst shape for this to fail in.
     *
     * AND THE OLD COPY WAS NEVER REMOVED, so those same two flows then recorded TWICE —
     * once here and once there — writing two identical rows for one sign-in. The test that
     * covers that path takes `firstOrFail()` rather than counting, so it stayed green; its
     * neighbour, on the door that only ever recorded once, is the one that asserts a count
     * of exactly 1. Deleting `AccountAuth` removes the second write by construction, and
     * this is now the only place a sign-in is recorded.
     *
     * What the entry costs is the reason it exists: `last_login_at` is a single overwritten
     * column, so without a chained entry per session a takeover looks exactly like the
     * owner's own next visit.
     *
     * BOTH LANDINGS CALL IT, because there are two: {@see establish()} for the doors that
     * mint a session, and {@see adopt()} for the ones that hand over a session already
     * started (magic-link redemption). Recording in only the first is how a door goes
     * quiet — the same way this did.
     *
     * A subject with no membership records nothing: an ordinary tenant user has no
     * management chain to write to.
     *
     * Failure is swallowed. The audit chain serialises appends on an anchor row, and a
     * backend that is down or contended must not be able to lock every member out of a
     * console they hold the right credential for. Observation, not gate.
     */
    private function recordOrganizationSignIn(string $subjectId): void
    {
        try {
            $this->platformRoot->run(function () use ($subjectId): bool {
                $membership = $this->memberships->forUser($subjectId)->first();

                if ($membership === null) {
                    return false;
                }

                $this->activity->record(
                    $membership->organization_id,
                    'organization.signed_in',
                    $subjectId,
                    targetType: 'membership',
                    targetId: $membership->id,
                    request: request(),
                );

                return true;
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Attempt a password login. Returns 'ok', 'mfa', 'otp', 'sso_required' or 'invalid'.
     * On 'mfa' and 'otp' the subject is held pending a second factor — no session yet;
     * on 'sso_required' the credential verified but a tenant mandates single sign-on, so
     * there is no second step here at all and the door has to send them to their IdP.
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
            // NOBODY HERE BY THAT NAME — which during a migration does not mean nobody at
            // all. If a legacy source is bound it gets the one chance to say otherwise,
            // and a person it recognises is created here as a result of the login that
            // just succeeded. This runs ONLY when there is no local user, which is rule 1
            // on LegacyCredentialSource: somebody who has already migrated authenticates
            // against what is HERE, and the old system never gets a second vote on a
            // password they may have changed since.
            $subject = $this->legacy?->migrate($email, $password);
        }

        if ($subject === null) {
            // Do equivalent hashing work so a missing account isn't measurably
            // faster than a wrong password — closes the email-enumeration oracle.
            //
            // The legacy lookup above costs a query or an HTTP hop that a local hit does
            // not, which is a new signal — but it is paid for every unknown address
            // regardless of the answer, so it separates "not migrated yet" from "already
            // migrated", never "exists" from "does not".
            $this->hasher->check($password, self::timingHash());

            return AttemptOutcome::Invalid;
        }

        // A tenant's lockout threshold binds BEFORE the credential is checked, or a
        // locked account still tells an attacker which guess was right. The IP-keyed
        // limiter the form already applies is a different control: it protects the
        // service from a flood, and an attacker spreading guesses across a botnet never
        // trips it while hammering one account.
        if ($this->loginAttempts->isLockedOut($subject->id)) {
            return AttemptOutcome::Invalid;
        }

        if (! $this->subjects->verifyPassword($subject->id, $password)) {
            $this->loginAttempts->recordFailure($subject->id);

            return AttemptOutcome::Invalid;
        }

        $this->loginAttempts->clear($subject->id);

        // A temporary password an administrator issued stops admitting anyone once its
        // deadline passes, even though the hash still matches — otherwise a hand-off
        // credential lingers as a permanent second way in.
        if ($this->adminPasswords->hasExpired($subject->id)) {
            return AttemptOutcome::Invalid;
        }

        // A tenant that mandates SSO means it: a local password must not be a way around
        // the identity provider they chose. Still checked AFTER the credential verifies,
        // which is what keeps it from being an account-existence oracle: an address that
        // does not exist here, and a wrong guess against one that does, both end above
        // with Invalid and never reach this line.
        //
        // It is answered with its OWN outcome rather than Invalid. The population that
        // reaches it is exactly the people who typed the right password, and telling them
        // it was wrong is how this refusal dead-ended — see {@see AttemptOutcome::SsoRequired}
        // for what that distinction does and does not disclose.
        if (! $this->localSignInAllowedFor($subject->id)) {
            return AttemptOutcome::SsoRequired;
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

    /**
     * Whether a LOCAL sign-in — any of them — may still establish a session for this
     * subject.
     *
     * A subject belongs to organizations, each of which may mandate SSO. If ANY of them
     * requires it, a local sign-in is refused — the strictest membership wins, so a user
     * cannot sidestep a mandating tenant by holding a second, laxer membership. The
     * environment's own baseline is asked last, so an operator-wide mandate covers the
     * subjects who belong to no organization at all.
     *
     * It was `passwordLoginAllowedFor()`, and the name was the bug. `SsoEnforcement::Required`
     * says SSO is the only way in and the console says so in those words — but only the
     * password doors asked, so a magic link, a passkey, an operator social button, an
     * accepted invitation and a workspace password reset all still opened a session in an
     * organization that had been told it had closed them. A mandate is a statement about
     * WHICH DIRECTORY decides, not about which credential was typed, and a name that said
     * "password" is what let four doors read it as somebody else's rule.
     *
     * Public because every door asks it of the same subject: account members are ordinary
     * subjects in the platform-root environment, so an account whose organization mandates
     * SSO must not be able to sidestep it by picking a different door. One implementation,
     * every door — the whole point of the unification is that this rule is not written twice.
     */
    public function localSignInAllowedFor(string $subjectId): bool
    {
        foreach ($this->memberships->forUser($subjectId) as $membership) {
            if (! $this->policies->resolve($membership->organization_id)->sso->allowsPasswordLogin()) {
                return false;
            }
        }

        return $this->policies->resolve()->sso->allowsPasswordLogin();
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
     * Hold a subject for a second factor, as `attemptPassword()` does after a good password.
     *
     * EXISTS FOR THE EMBEDDED CHANNEL, and deliberately narrow. A page on somebody else's
     * origin carries no session cookie between the password request and the code request,
     * so the pending state travels as a signed ticket and has to be put back HERE before
     * `completeMfa()` can find it. The alternative was a second implementation of the
     * challenge in the controller, which would be the one missing whatever this method
     * grows next.
     *
     * It grants nothing on its own: everything downstream still demands a correct code,
     * and the caller can only reach this with a ticket minted by a completed password
     * check in the same environment.
     */
    public function holdForMfa(Request $request, string $subjectId): void
    {
        $request->session()->put(self::MFA_PENDING_KEY, $subjectId);
    }

    /** The same, for an emailed step-up code. See {@see holdForMfa()}. */
    public function holdForOtpStepUp(Request $request, string $subjectId, string $email = ''): void
    {
        $request->session()->put(self::OTP_PENDING_KEY, ['subject' => $subjectId, 'email' => $email]);
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
        // Plus the sudo confirmation: it attests that the person at the keyboard is who
        // this session says, and minting a new session is exactly the moment that stops
        // being established. regenerate() rotates the id but preserves the data, so
        // without this a step-up made before a transition carried straight through it —
        // and the sudo gate is meant to be the independent second layer, not something
        // load-bearing on whatever else happens to refuse first.
        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, Sudo::SESSION_KEY, EnvironmentSudo::SESSION_KEY]);

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
        $this->recordOrganizationSignIn($subjectId);
    }

    /**
     * Adopt a framework session that was already started (e.g. by magic-link
     * redemption) into the browser cookie.
     */
    public function adopt(Request $request, Session $session): void
    {
        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, Sudo::SESSION_KEY, EnvironmentSudo::SESSION_KEY]);

        $organizationId = $session->organization_id
            ?? $this->memberships->forUser($session->user_id)->value('organization_id');

        $this->addAccount($session->user_id, $session->id, is_string($organizationId) ? $organizationId : null);

        session()->regenerate();

        $this->applyPendingLink($session->user_id);
        $this->recordOrganizationSignIn($session->user_id);
    }

    /**
     * Hold a verified social identity aside while the user proves control of the
     * existing account by signing in. Linking then completes automatically.
     */
    public function startPendingLink(FederatedPrincipal $principal): void
    {
        session()->put(self::PENDING_LINK_KEY, PendingLink::hold($principal, now()->getTimestamp())->toArray());
    }

    /**
     * The held identity, or null when there is none, it has expired, or it is not the
     * one this subject was asked about.
     *
     * Expiry is enforced on READ rather than by a scheduled sweep, because the session
     * is the only place it lives — nothing else would ever come along to clear it.
     */
    public function pendingLink(?string $subjectId = null): ?PendingLink
    {
        $pending = PendingLink::fromArray(session()->get(self::PENDING_LINK_KEY));

        if ($pending === null || $pending->expired(now()->getTimestamp())) {
            session()->forget(self::PENDING_LINK_KEY);

            return null;
        }

        if ($subjectId !== null && $pending->awaitingSubjectId !== $subjectId) {
            return null;
        }

        return $pending;
    }

    /** Discard a held identity — the "No, that wasn't me" answer. */
    public function discardPendingLink(): void
    {
        session()->forget(self::PENDING_LINK_KEY);
    }

    /**
     * Attach the held identity to the account that was asked about, and only that one.
     *
     * This replaced an automatic link gated on the held identity's email matching the
     * account's. That test was wrong in both directions. Too strict: people have several
     * addresses at one provider — a GitHub account may carry five — so the legitimate
     * owner's link was silently discarded and the flow simply appeared not to work. Too
     * weak: it leant on the provider having verified the address, which is the one thing
     * we had already decided we cannot assume. Discord will carry whatever someone typed.
     *
     * Asking outright is both safer and kinder. Confirming proves three things at once:
     * control of the provider account (they completed its sign-in), control of this
     * account (they authenticated here), and intent (they said so). No address equality
     * is required, and none is implied.
     */
    public function confirmPendingLink(string $subjectId): bool
    {
        $pending = $this->pendingLink($subjectId);

        if ($pending === null) {
            return false;
        }

        session()->forget(self::PENDING_LINK_KEY);

        try {
            $this->subjects->link($subjectId, $pending->principal());
        } catch (IdentityAlreadyLinked) {
            // Attached elsewhere in the meantime. Nothing to do, and nothing to tell the
            // user that would not also tell them where that identity lives.
            return false;
        }

        return true;
    }

    /**
     * Bind a held identity to the account that just authenticated — WITHOUT linking it.
     *
     * Called from every path that establishes a session, so the interstitial cannot be
     * dodged by arriving via magic link, MFA or an invitation instead of the password
     * form. The link itself only ever happens in confirmPendingLink().
     */
    private function applyPendingLink(string $subjectId): void
    {
        $pending = $this->pendingLink();

        if ($pending === null) {
            return;
        }

        session()->put(self::PENDING_LINK_KEY, $pending->awaiting($subjectId)->toArray());
    }

    /**
     * End any step-up window. Call before every `regenerate()` in this class.
     *
     * `Sudo` stores a bare timestamp under one global session key —
     * no subject, no member, no session id — and `regenerate()` rotates the id while
     * KEEPING the data. So a window confirmed as one identity is, mechanically, a window
     * that belongs to whoever the session is carrying afterwards.
     *
     * Three transitions did not clear it: `switchTo()`, `logout()`'s multi-account
     * branch, and `AccountAuth::establish()`. Each hands the session to a different
     * person, and every sudo-gated route then accepts it: passkey enrolment, provider
     * linking, vault reveal, and on the account plane the minting of account and
     * environment API keys. That converts a session obtained through a door that never
     * asks for a password — a redeemed magic link, an SSO assertion — into durable
     * credentials that outlive the victim revoking the vector and resetting their
     * password, which is the exact persistence the step-up exists to prevent.
     *
     * A comment two methods down used to assert the account switch "has always done
     * both". It had not. Hence one helper rather than four call sites: a fifth
     * transition added later cannot forget what it never had to remember.
     */
    private function dropStepUp(): void
    {
        session()->forget([Sudo::SESSION_KEY, EnvironmentSudo::SESSION_KEY]);
    }

    public function switchOrganization(Request $request, string $organizationId): void
    {
        // Switching tenant changes which authority the session carries, so a step-up
        // confirmed against the previous one does not transfer — and the id rotates here
        // like every other privilege transition.
        $this->dropStepUp();
        session()->regenerate();

        session()->put(self::ORG_KEY, $organizationId);

        // Keep the active account's remembered org in sync, so switching accounts
        // and back restores the org the user was in.
        $accounts = $this->accountsMap();
        $active = session()->get(self::ACTIVE_KEY);

        if (is_string($active) && isset($accounts[$active])) {
            $accounts[$active] = $accounts[$active]->withOrganization($organizationId);
            $this->writeAccounts($accounts);
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

        foreach ($accounts as $subjectId => $held) {
            $subject = $this->sessions->active($held->sessionId) !== null
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
                'organization_id' => $held->organizationId,
                'active' => $subjectId === $active,
            ];
        }

        if ($changed) {
            $this->writeAccounts($accounts);
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

        $held = $accounts[$subjectId];

        if ($this->sessions->active($held->sessionId) === null) {
            unset($accounts[$subjectId]);
            $this->writeAccounts($accounts);

            return false;
        }

        $this->makeActive($subjectId, $held->sessionId, $held->organizationId);

        // Rotate the id on privilege change (the active identity just changed) — and
        // end the step-up with it, because the identity it was confirmed against is not
        // the one the session now carries.
        $this->dropStepUp();
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
            $this->sessions->revoke($accounts[$active]->sessionId);
            unset($accounts[$active]);
        } else {
            // No tracked active account — revoke whatever the derived key points at.
            $sessionId = session()->get(self::SESSION_KEY);

            if (is_string($sessionId)) {
                $this->sessions->revoke($sessionId);
            }
        }

        session()->forget([self::MFA_PENDING_KEY, self::OTP_PENDING_KEY, self::PENDING_LINK_KEY]);
        $this->dropStepUp();

        if ($accounts !== []) {
            $next = array_key_first($accounts);
            $this->writeAccounts($accounts);
            $this->makeActive($next, $accounts[$next]->sessionId, $accounts[$next]->organizationId);
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
        foreach ($this->accountsMap() as $held) {
            $this->sessions->revoke($held->sessionId);
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
            $this->sessions->revoke($accounts[$oldest]->sessionId);
            unset($accounts[$oldest]);
        }

        // Re-insert at the end so refresh keeps recency ordering.
        unset($accounts[$subjectId]);
        $accounts[$subjectId] = new HeldAccount($sessionId, $organizationId);

        $this->writeAccounts($accounts);
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
     * @return array<string, HeldAccount>
     */
    private function accountsMap(): array
    {
        $raw = session()->get(self::ACCOUNTS_KEY);

        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $subjectId => $entry) {
            $held = HeldAccount::fromArray($entry);

            if (is_string($subjectId) && $held !== null) {
                $map[$subjectId] = $held;
            }
        }

        return $map;
    }

    /**
     * Persist the held-account set, flattening the typed map back to the session's
     * plain-array shape (the serialization boundary).
     *
     * @param  array<string, HeldAccount>  $accounts
     */
    private function writeAccounts(array $accounts): void
    {
        session()->put(self::ACCOUNTS_KEY, array_map(
            static fn (HeldAccount $held): array => $held->toArray(),
            $accounts,
        ));
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
