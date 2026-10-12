<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\Enums\RefusedFactor;
use App\Platform\FederatedLanding;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\RiskGuard;
use App\Platform\SsoRefusal;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SmsFactors;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\AccountInactive;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\Exceptions\SmsFactorRefused;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Identity\ValueObjects\SmsCodeSent;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Otp\Contracts\OtpService;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Cbox\Id\Otp\Sms\Exceptions\SmsDeliveryFailed;
use Cbox\Id\Otp\Sms\Exceptions\SmsSendRefused;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * LINK AT SIGN-IN — "Link your Google account to sn@cbox.dk?".
 *
 * Somebody signs in with a provider, and the address it vouches for already belongs to an
 * account here. Both obvious answers are wrong, and the product used to give one of them:
 *
 *  - SIGN THEM IN to that account because the addresses match. That trusts the provider's
 *    word for the address as proof of ownership of OUR account, and is the textbook
 *    account-takeover: anybody who can get a provider to say an address — a lax provider, a
 *    recycled corporate mailbox, a tenant-run IdP — walks into the account that uses it.
 *  - REFUSE, with "sign in with your password, then connect it from your settings". That
 *    was the answer, it rendered as nothing at all (see {@see FederationFailure}), and the
 *    settings page it pointed at had no such control.
 *
 * So the person is asked to prove they hold the EXISTING account, on a hosted step, and
 * the identity is linked only once they have: the provider's subject id (never its
 * address) is attached, and they are signed in and sent where they were going — the same
 * landing a sign-in through that provider would have had, including the root's hand-off
 * into a tenant's `/admin`.
 *
 * WHAT COUNTS AS PROOF follows from what the account already demands, strongest first:
 *
 *  1. An account with a SECOND FACTOR answers with it — the authenticator (or one of its
 *     recovery codes), or a texted code. A link is a new way in that skips the second
 *     factor on every sign-in after it, so it is authorised by that factor or not at all.
 *     A password alone would let somebody holding a breached password add a way in that
 *     never asks for the factor the owner turned on.
 *  2. An account with a PASSWORD answers with it, or with a code mailed to the account's
 *     own address — which is no weaker, because a password reset needs nothing more.
 *  3. Anything else answers with the mailed code.
 *
 * A Radar CHALLENGE withdraws the password: possession (the inbox, the factor) is asked
 * for instead, which is what a challenge means on the password door too. A Radar BLOCK
 * ends the attempt with the generic sentence. A mandate (an organization that requires
 * its own SSO) refuses a link through any provider that is not that SSO, on the same
 * screen every other door shows it on. A code mailed and answered also confirms the
 * address — it is exactly the proof a confirmation link is — but the provider's say-so
 * never does: that gate ({@see VerifiedEmailGate}) is about OUR evidence.
 *
 * Every wrong answer counts against the hold ({@see PendingLink::MAX_FAILURES}) AND the
 * account's own lockout, and is reported to Radar as a failed sign-in; the routes in
 * front are throttled per source as well.
 *
 * It is offered only when the provider vouched for the address ({@see VouchedEmail}).
 * Otherwise the person is told, in a sentence, and can connect the provider from My
 * account, where they are already signed in.
 */
final readonly class AccountLinking
{
    /** The OTP purpose the mailed code is issued and checked under — never shared with a sign-in step-up. */
    public const OTP_PURPOSE = 'link_identity';

    public function __construct(
        private Subjects $subjects,
        private LinkHold $hold,
        private FederatedLanding $landing,
        private RiskGuard $risk,
        private PlatformAuth $auth,
        private Mfa $mfa,
        private SmsFactors $sms,
        private OtpService $otp,
        private LoginAttempts $attempts,
        private AdminPasswords $adminPasswords,
        private Connections $connections,
        private FederationFlow $flow,
        private AuditLog $audit,
    ) {}

    /**
     * The provider vouched for an address that already has an account: hold the identity
     * and send the person to the link step — or say why not.
     *
     * @param  Connection|null  $connection  the environment or organization connection it came through; null for an operator provider
     * @param  string|null  $operatorProvider  the operator provider's key (`google`), when it came through one
     */
    public function begin(
        Request $request,
        FederatedPrincipal $principal,
        ?Connection $connection,
        ?string $operatorProvider,
        string $providerLabel,
        string $providerKey,
    ): RedirectResponse {
        $label = ['provider' => $providerLabel];

        if (! VouchedEmail::for($principal, $connection)) {
            return $this->landing->failed(FederationFailure::Unverified, [...$label, 'email' => (string) $principal->email]);
        }

        $account = $this->subjects->findByEmail((string) $principal->email);

        // The framework said the address is taken, so this is a race with a deletion. The
        // honest answer is the same as for any other sign-in that did not complete.
        if ($account === null) {
            return $this->landing->failed(FederationFailure::Expired, $label);
        }

        if (! $this->subjects->isActive($account->id)) {
            return $this->landing->failed(FederationFailure::AccountDisabled, $label);
        }

        /*
         * A MANDATE CLOSES THIS DOOR TOO. An organization that requires its own SSO has
         * said which directory decides who its people are, and linking Google would be a
         * standing way around it. Its OWN connection is the exception: that IS the mandated
         * door, and linking through it is how a member whose account predates the
         * connection gets onto it.
         */
        $mandatedHere = $connection?->organization_id !== null;

        if (! $mandatedHere && ! $this->auth->localSignInAllowedFor($account->id)) {
            SsoRefusal::hold($account->id, RefusedFactor::Social);

            return redirect()->route('login');
        }

        // Radar sees this as what it is: an attempt to get into an existing account. Scored
        // as a password sign-in, the conservative reading — the person has not proved
        // possession of anything of ours yet.
        $decision = $this->risk->assess($request, 'login', $account->email, method: RadarMethod::Password);

        if ($this->risk->shouldBlock($decision)) {
            return $this->landing->failed(FederationFailure::Refused, $label);
        }

        $link = PendingLink::hold(
            principal: $principal,
            subjectId: $account->id,
            accountEmail: (string) $account->email,
            providerLabel: $providerLabel,
            providerKey: $providerKey,
            operatorProvider: $operatorProvider,
            challenged: $this->risk->shouldStepUp($decision),
            now: now()->getTimestamp(),
        );

        // Only the mailed code is possible: send it now rather than make the person press
        // a button to ask for the one thing the page can offer.
        if ($this->factors($link) === ['totp' => false, 'sms' => false, 'password' => false, 'email' => true]) {
            $link = $this->sendEmailCode($request, $link) ?? $link;
        }

        $this->hold->put($link);

        return redirect()->route('login.link');
    }

    /** The live hold, for the step's page and its answers. */
    public function pending(): ?PendingLink
    {
        return $this->hold->current();
    }

    /** "Don't link" — the hold is dropped and nothing changes on the account. */
    public function cancel(): void
    {
        $this->hold->forget();
    }

    /**
     * Which answers the step accepts for this account, by the rule in the class docblock.
     *
     * @return array{totp: bool, sms: bool, password: bool, email: bool}
     */
    public function factors(PendingLink $link): array
    {
        $totp = $this->mfa->hasConfirmedTotp($link->subjectId);
        $sms = $this->sms->isUsable($link->subjectId);
        $secondFactor = $totp || $sms;

        return [
            'totp' => $totp,
            'sms' => $sms,
            'password' => ! $secondFactor && ! $link->challenged && $this->hasPassword($link->subjectId),
            'email' => ! $secondFactor,
        ];
    }

    /**
     * Mail a one-time code to the ACCOUNT's own address — the one on file here, never the
     * string the provider sent, even though they match: the proof is about our inbox.
     *
     * Null when the OTP service's own issuance cap refused; the caller words that.
     */
    public function sendEmailCode(Request $request, PendingLink $link): ?PendingLink
    {
        try {
            $this->otp->issue(self::OTP_PURPOSE, $link->accountEmail, 'email', $request->ip());
        } catch (OtpRateLimitExceeded) {
            return null;
        }

        $sent = $link->sentCode();
        $this->hold->put($sent);

        return $sent;
    }

    /**
     * Text a code to the account's confirmed number. Null when nothing could be sent —
     * no usable number, a locked account, or one of the SMS guard's caps.
     */
    public function sendSms(Request $request, PendingLink $link): ?SmsCodeSent
    {
        if ($this->attempts->isLockedOut($link->subjectId)) {
            return null;
        }

        try {
            return $this->sms->sendChallenge($link->subjectId, $request->ip(), app()->getLocale());
        } catch (SmsFactorRefused|OtpRateLimitExceeded|SmsSendRefused|SmsDeliveryFailed) {
            return null;
        }
    }

    /**
     * Check one answer against the account. A wrong one is counted — against the hold, the
     * account's lockout and Radar — and the updated hold is written back.
     */
    public function prove(Request $request, PendingLink $link, LinkProof $proof, string $answer): bool
    {
        $factors = $this->factors($link);

        $offered = match ($proof) {
            LinkProof::Totp, LinkProof::RecoveryCode => $factors['totp'],
            LinkProof::Sms => $factors['sms'],
            LinkProof::Password => $factors['password'],
            LinkProof::EmailCode => $factors['email'] && $link->codeSent,
            LinkProof::SignedIn => false,
        };

        // Checked BEFORE the answer, as every door does: a locked account must not go on
        // telling an attacker which guess was right.
        $right = $offered
            && ! $this->attempts->isLockedOut($link->subjectId)
            && match ($proof) {
                LinkProof::Totp => $this->mfa->verifyTotp($link->subjectId, $answer),
                LinkProof::RecoveryCode => $this->mfa->verifyRecoveryCode($link->subjectId, $answer),
                LinkProof::Sms => $this->sms->verifyChallenge($link->subjectId, $answer, $request->ip()),
                LinkProof::Password => ! $this->adminPasswords->hasExpired($link->subjectId)
                    && $this->subjects->verifyPassword($link->subjectId, $answer),
                LinkProof::EmailCode => $this->otp->verifyLatest(self::OTP_PURPOSE, $link->accountEmail, $answer, $request->ip())->verified,
                LinkProof::SignedIn => false,
            };

        if ($right) {
            return true;
        }

        $this->attempts->recordFailure($link->subjectId);
        $this->risk->failed($request, $link->accountEmail);

        $failed = $link->failed();

        // A hold that has had its five guesses is gone: the person starts again from the
        // provider, which costs them one click and costs a guesser a whole new sign-in.
        $failed->exhausted() ? $this->hold->forget() : $this->hold->put($failed);

        return false;
    }

    /**
     * The proof is in: attach the identity, record it, and sign the person in — landing
     * exactly where a sign-in through that provider would have.
     *
     * The hold is PULLED first, so a second answer racing this one finds nothing.
     */
    public function complete(Request $request, string $holdId, LinkProof $proof): RedirectResponse
    {
        $link = $this->hold->pull($holdId);

        if ($link === null) {
            return $this->landing->failed(FederationFailure::LinkExpired);
        }

        $label = ['provider' => $link->providerLabel];
        $principal = $link->principal();

        // A connection that was switched off while the person found their code: linking
        // to it would add a way in that cannot be used, and signing in through it is
        // exactly what turning it off was meant to stop.
        $connection = $link->connectionId === null ? null : $this->connections->byId($link->connectionId);

        if ($link->connectionId !== null && ($connection === null || ! $connection->isActive())) {
            return $this->landing->failed(FederationFailure::Inactive, $label);
        }

        try {
            $this->subjects->link($link->subjectId, $principal);
        } catch (IdentityAlreadyLinked) {
            return $this->landing->failed(FederationFailure::AlreadyLinked, $label);
        }

        $this->recordLinked($link->subjectId, $principal, $link->providerKey, $proof, 'sign_in');

        // The mailed code answered from the account's own inbox IS a confirmation of the
        // address. The provider's claim alone never is.
        if ($proof === LinkProof::EmailCode && $this->subjects->find($link->subjectId)?->emailVerified === false) {
            $this->subjects->markEmailVerified($link->subjectId, $link->accountEmail);
        }

        $linked = __('auth.link_account.linked', $label);
        $linked = is_string($linked) ? $linked : '';

        if ($connection !== null) {
            try {
                // The same completion the callback would have run, now that the identity
                // resolves: a session, the organization's membership where the connection
                // has one, the `user.login` entry — and the landing's hand-off.
                $session = $this->flow->completeLogin($connection, $principal);
            } catch (AccountInactive) {
                return $this->landing->failed(FederationFailure::AccountDisabled, $label);
            } catch (InvalidAssertion|ConnectionInactive) {
                return $this->landing->failed(FederationFailure::Inactive, $label);
            }

            return $this->landing->land($request, $session)->with('status', $linked);
        }

        $this->auth->establish($request, $link->subjectId, ['social', (string) $link->operatorProvider]);

        return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'))->with('status', $linked);
    }

    /**
     * `user.identity_linked`, on the account's own trail, by the account.
     *
     * The framework writes `identity.linked` too, as the system. This one says what the
     * framework cannot know: that a PERSON did it, through which door (`sign_in` — the link
     * step; `account` — My account), and with what proof. The provider's subject is
     * recorded, never its address.
     */
    public function recordLinked(string $subjectId, FederatedPrincipal $principal, string $providerKey, LinkProof $proof, string $via): void
    {
        $this->audit->record(new AuditEvent(
            action: 'user.identity_linked',
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'user',
            targetId: $subjectId,
            context: [
                'provider' => $providerKey,
                'identity_provider' => $principal->provider,
                'identity_subject' => $principal->subject,
                'connection_id' => $principal->connectionId,
                'proof' => $proof->value,
                'through' => $via,
            ],
        ));
    }

    private function hasPassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        // config() is untyped; is_a(..., allow_string: true) is what turns the value into a
        // class-string<Model> the query builder can be resolved against.
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return false;
        }

        return $model::query()->whereKey($subjectId)->value('password') !== null;
    }
}
