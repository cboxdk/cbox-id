<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\RemovePasskey;
use App\Actions\Account\RemoveSmsFactor;
use App\Actions\Account\UnlinkSocialAccount;
use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Console\RunsActions;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Account\ChangeOwnPasswordRequest;
use App\Http\Requests\Account\SaveProfileRequest;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use App\Platform\Social\OperatorProvider;
use App\Platform\Social\OperatorProviders;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\PrivilegedSubjects;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Contracts\SmsFactors;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\SmsFactorRefused;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\LinkedIdentity;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Cbox\Id\Otp\Sms\Exceptions\SmsDeliveryFailed;
use Cbox\Id\Otp\Sms\Exceptions\SmsSendRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * "MY ACCOUNT" — every person's self-service security centre: their name, their password,
 * a second factor, passkeys, connected accounts and the session they are holding.
 *
 * Available to anyone signed in, member or administrator; organization management lives in
 * Settings, and the fuller "where am I signed in, what can act as me" view is its own page
 * ({@see AccountActivityController}) because a person arrives there with a different
 * question and usually in a hurry.
 *
 * EVERY WRITE HERE EXCEPT ONE IS BEHIND `sudo`, on the ROUTE. Under Volt each action had to
 * call a private `requiresSudo()` first, because every action arrived at the same endpoint
 * and route middleware could not see them; there is a route per write now, so the gate is
 * the stack's. The exception is the display name — it is not a credential, and changing it
 * grants nothing.
 */
final readonly class AccountController extends PageController
{
    use RunsActions;

    /** Attempts allowed against a freshly-generated TOTP secret before backing off. */
    private const ENROL_ATTEMPTS = 5;

    private const ENROL_DECAY = 300;

    public function show(Request $request, Mfa $mfa, MfaMandate $mandate, Subjects $subjects, OperatorProviders $providers, SmsFactors $sms): Response
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $subjectId = $me->id();
        $session = $me->session();
        $organization = $me->organization();

        return $this->page('account/security', 'Security', [
            'help' => HelpProps::for(HelpTopic::AccountSecurity),
            'returnTo' => $this->validReturnTo($request),
            'profile' => [
                'name' => $me->name(),
                'email' => $me->email(),
                'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
                'organization' => $organization === null ? null : [
                    'name' => $organization->name,
                    'role' => $me->role()?->label() ?? 'Member',
                ],
            ],
            'hasPassword' => $this->hasPassword($subjectId),
            'twoFactor' => [
                'enabled' => $mfa->hasConfirmedTotp($subjectId),
                /*
                 * Whether a second factor is offered here AT ALL — the "Not offered"
                 * setting on the auth-policy screen, which until it was wired here decided
                 * nothing: `MfaRequirement::Off` and `Optional` were indistinguishable
                 * everywhere in the product. Said out loud on the page rather than by an
                 * absent button, because somebody looking for the panel deserves to know it
                 * was a decision and not a missing feature.
                 */
                'offered' => $mandate->offersEnrolment($subjectId),
                'recoveryRemaining' => $mfa->remainingRecoveryCodes($subjectId),
            ],
            'smsFactor' => $this->smsFactorProps($subjectId, $mfa, $mandate, $sms),
            'passkeys' => WebAuthnCredential::query()
                ->where('user_id', $subjectId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (WebAuthnCredential $passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name ?? 'Passkey',
                    'added' => $passkey->getAttribute('created_at') instanceof CarbonInterface
                        ? $passkey->getAttribute('created_at')->format('M j, Y')
                        : null,
                    'signCount' => $passkey->sign_count,
                    'removeHref' => route('account.passkeys.destroy', $passkey->id),
                ])->values()->all(),
            // Whether a passkey may be ADDED here. Off for the environment (or the deployment)
            // takes the enrolment button away; the list stays, so a person can still see and
            // remove what they enrolled before.
            'passkeysEnabled' => app(SignInMethods::class)->passkeysEnabled(),
            'socialProviders' => $this->socialProviders($providers, $subjects, $subjectId),
            'session' => $session === null ? null : [
                'id' => $session->id,
                'methods' => $session->amr ?? [],
                'signedIn' => $session->created_at?->format('M j, Y g:i A'),
            ],
            'otherSessions' => $this->otherSessions($subjectId, $session?->id)->count(),
            'urls' => [
                'profile' => route('account.profile.update'),
                'password' => route('account.password.update'),
                'enrolMfa' => route('account.mfa.enrol'),
                'confirmMfa' => route('account.mfa.confirm'),
                'recoveryCodes' => route('account.mfa.recovery-codes'),
                'enrolSms' => route('account.mfa.sms.enrol'),
                'confirmSms' => route('account.mfa.sms.confirm'),
                'removeSms' => route('account.mfa.sms.destroy'),
                'signOutOthers' => route('account.sessions.revoke-others'),
                'logout' => route('logout'),
                'activity' => route('account.activity'),
            ],
        ]);
    }

    /** Change the display name through {@see UpdateProfile}, the account plane's action. */
    public function updateProfile(SaveProfileRequest $request): RedirectResponse
    {
        $result = $this->act(UpdateProfile::class, ['name' => $request->displayName()], ['name' => 'displayName'], 'displayName');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // Pushed back so every surface bound to CurrentUser — the avatar initial, the
        // greeting, the passkey label — reflects the new name on the redirect that follows
        // rather than one request later.
        if ($result->value instanceof Subject) {
            app(CurrentUser::class)->refreshSubject($result->value);
        }

        return back()->with('status', 'Name updated.');
    }

    public function updatePassword(ChangeOwnPasswordRequest $request, Subjects $subjects): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        /*
         * THE CURRENT PASSWORD IS ASKED FOR ONLY WHEN THERE IS ONE. Somebody who signed up
         * with a social account or a passkey is setting a first password here, and there is
         * nothing to verify — demanding it would lock them out of the panel that exists to
         * give them a second way in.
         */
        if ($this->hasPassword($me->id()) && ! $subjects->verifyPassword($me->id(), $request->currentPassword())) {
            return back()->withErrors(['currentPassword' => 'That password is incorrect.']);
        }

        if ($request->newPassword() !== $request->newPasswordConfirmation()) {
            return back()->withErrors(['newPasswordConfirmation' => 'The passwords do not match.']);
        }

        $subjects->setPassword($me->id(), $request->newPassword());

        return back()->with('status', 'Password updated.');
    }

    /**
     * Begin TOTP enrolment: mint a secret and hand back the QR code to scan.
     *
     * THE SECRET TRAVELS ON THE FLASH CHANNEL, not in the page's props. It is a credential
     * shown once, and Inertia's flash is not written into the history entry — so a back
     * button, a restored tab or a shared session snapshot cannot resurrect it.
     */
    public function enrolMfa(Mfa $mfa, MfaMandate $mandate): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        /*
         * BEFORE anything else, because this is not a question about how sure we are that
         * it is you — it is whether the feature exists here at all. A button removed from a
         * page is a styling decision; this is the rule.
         */
        abort_unless($mandate->offersEnrolment($me->id()), 403);

        $enrolment = $mfa->enrollTotp($me->id(), $me->email() ?? $me->name(), 'Cbox ID');

        $this->inertia->flash([
            'mfaSecret' => $enrolment->secret,
            'mfaQrCode' => $this->qrCode($enrolment->provisioningUri),
        ]);

        return back();
    }

    public function confirmMfa(Request $request, Mfa $mfa): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $request->validate(['code' => ['required', 'digits:6']]);

        $code = (string) $request->string('code');

        /*
         * BOUNDED, like every other screen that checks a typed secret. The exposure here is
         * genuinely small — this confirms a secret the person just generated for themselves,
         * behind a sudo gate — but "small" is an argument for a cheap limiter rather than
         * against one, and an exception carved for this one screen is an exception the next
         * author copies.
         */
        $key = 'mfa-enrol|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, self::ENROL_ATTEMPTS)) {
            return back()->withErrors([
                'code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! $mfa->confirmTotp($me->id(), $code)) {
            RateLimiter::hit($key, self::ENROL_DECAY);

            return back()->withErrors(['code' => 'That code did not match. Try again.']);
        }

        RateLimiter::clear($key);

        // Shown exactly once, so they travel the same channel as the secret above.
        $this->inertia->flash('recoveryCodes', $mfa->generateRecoveryCodes($me->id()));

        return back()->with('status', 'Two-factor authentication is now enabled. Save your recovery codes below.');
    }

    public function regenerateRecoveryCodes(Mfa $mfa): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        // Nothing to regenerate for somebody with no confirmed factor, and minting codes
        // that unlock a factor they do not have is worse than doing nothing. A confirmed
        // phone number is a factor too.
        abort_unless($mfa->hasConfirmedTotp($me->id()) || app(SmsFactors::class)->isEnrolled($me->id()), 403);

        $this->inertia->flash('recoveryCodes', $mfa->generateRecoveryCodes($me->id()));

        return back()->with('status', 'New recovery codes generated. Your previous codes no longer work.');
    }

    /**
     * Start adding a phone number as a second factor: the number is stored sealed and
     * unconfirmed, and a code is texted to it. A CEREMONY, like the authenticator app's —
     * the phone itself has to take part — so it stays on this page rather than becoming an
     * action an API could run.
     *
     * The number is never echoed back in full: the page is told the masked form, on the
     * flash channel, and draws the confirmation step from that.
     */
    public function enrolSms(Request $request, MfaMandate $mandate, SmsFactors $sms): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);
        abort_unless($mandate->offersEnrolment($me->id()), 403);

        $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        try {
            $sent = $sms->beginEnrolment(
                $me->id(),
                (string) $request->string('phone'),
                $request->filled('country') ? strtoupper((string) $request->string('country')) : null,
                $request->ip(),
                app()->getLocale(),
            );
        } catch (SmsFactorRefused $refused) {
            return back()->withErrors(['phone' => $refused->getMessage()]);
        } catch (OtpRateLimitExceeded|SmsSendRefused) {
            return back()->withErrors(['phone' => 'Too many codes have been sent. Wait a few minutes and try again.']);
        } catch (SmsDeliveryFailed $failed) {
            report($failed);

            return back()->withErrors(['phone' => 'We could not send the text message. Check the number and try again.']);
        }

        $this->inertia->flash('smsEnrolmentSentTo', $sent->maskedNumber);

        return back();
    }

    /**
     * Prove the number with the texted code. The first second factor someone confirms also
     * gets them recovery codes, exactly as the authenticator app does: SMS is the factor
     * most likely to be lost with the phone.
     */
    public function confirmSms(Request $request, Mfa $mfa, SmsFactors $sms): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $request->validate(['smsCode' => ['required', 'regex:/^\d{6,10}$/']]);

        // The same bound as the authenticator's confirmation, on its own key.
        $key = 'mfa-sms-enrol|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, self::ENROL_ATTEMPTS)) {
            return back()->withErrors([
                'smsCode' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! $sms->confirmEnrolment($me->id(), (string) $request->string('smsCode'), $request->ip())) {
            RateLimiter::hit($key, self::ENROL_DECAY);

            return back()->withErrors(['smsCode' => 'That code did not match, or it has expired. Try again or send a new one.']);
        }

        RateLimiter::clear($key);

        if ($mfa->remainingRecoveryCodes($me->id()) === 0) {
            $this->inertia->flash('recoveryCodes', $mfa->generateRecoveryCodes($me->id()));

            return back()->with('status', 'Text-message codes are on. Save your recovery codes below.');
        }

        return back()->with('status', 'Text-message codes are on.');
    }

    /**
     * Remove your phone number through {@see RemoveSmsFactor}, the account plane's action —
     * your authenticator app, passkeys and recovery codes stay.
     */
    public function removeSms(): RedirectResponse
    {
        $result = $this->act(RemoveSmsFactor::class, []);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Phone number removed.');
    }

    /**
     * Remove one of your passkeys through {@see RemovePasskey}. Somebody else's is not found:
     * a 404, not a 403 — it is a row this reader has no business learning exists.
     */
    public function removePasskey(string $passkey): RedirectResponse
    {
        $result = $this->act(RemovePasskey::class, ['passkey_id' => $passkey]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Passkey removed.');
    }

    /**
     * Disconnect a social account through {@see UnlinkSocialAccount}, which keeps the
     * LAST-FACTOR GUARD: never strip the only remaining way to sign in.
     */
    public function unlinkProvider(string $provider): RedirectResponse
    {
        $result = $this->act(UnlinkSocialAccount::class, ['provider' => $provider], ['provider' => 'unlink'], 'unlink');

        return $result instanceof RedirectResponse ? $result : back()->with('status', ucfirst($provider).' disconnected.');
    }

    public function signOutOtherSessions(SessionManager $sessions): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $this->otherSessions($me->id(), $me->session()?->id)
            ->each(fn (string $id) => $sessions->revoke($id));

        return back()->with('status', 'Signed out of all other sessions.');
    }

    /**
     * The SMS second factor as the page draws it — or null when this environment does not
     * offer it at all, so the panel is simply absent rather than a control that can only
     * ever refuse.
     *
     * `blocked` is the administrator rule said up front: an administrator adds SMS only next
     * to an authenticator app or a passkey, and finding that out after typing a number and
     * waiting for a text would be the wrong order.
     *
     * @return array{enrolled: bool, pending: bool, maskedNumber: string|null, countries: list<string>, blocked: bool, needsStrongerFactor: bool}|null
     */
    private function smsFactorProps(string $subjectId, Mfa $mfa, MfaMandate $mandate, SmsFactors $sms): ?array
    {
        $policy = app(SmsFactorPolicies::class)->forEnvironment();
        $details = $sms->details($subjectId);

        if ((! $policy->enabled || ! $mandate->offersEnrolment($subjectId)) && $details === null) {
            return null;
        }

        $hasStronger = $mfa->hasConfirmedTotp($subjectId)
            || WebAuthnCredential::query()->where('user_id', $subjectId)->exists();

        return [
            'enrolled' => $details?->confirmed === true,
            'pending' => $details !== null && ! $details->confirmed,
            'maskedNumber' => $details?->maskedNumber,
            'countries' => $policy->enabled ? $policy->allowedCountries : [],
            'blocked' => $policy->enabled
                && $details === null
                && $policy->privilegedNeedStrongerFactor
                && ! $hasStronger
                && app(PrivilegedSubjects::class)->isPrivileged($subjectId),
            'needsStrongerFactor' => $sms->needsStrongerFactor($subjectId),
        ];
    }

    /**
     * Every live session that is NOT the one making this request.
     *
     * @return Collection<int, string>
     */
    private function otherSessions(string $subjectId, ?string $currentId): Collection
    {
        /** @var Collection<int, string> $ids */
        $ids = Session::query()
            ->where('user_id', $subjectId)
            ->whereNull('revoked_at')
            ->when($currentId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $currentId))
            ->pluck('id');

        return $ids;
    }

    /**
     * The providers this deployment offers, and which of them this person has linked.
     *
     * @return list<array<string, mixed>>
     */
    private function socialProviders(OperatorProviders $providers, Subjects $subjects, string $subjectId): array
    {
        $linked = collect($subjects->linkedIdentities($subjectId))
            ->map(static fn (LinkedIdentity $identity): string => $identity->provider)
            ->all();

        return array_map(static fn (OperatorProvider $provider): array => [
            'key' => $provider->key(),
            'label' => $provider->label(),
            'linked' => in_array($provider->identityProvider(), $linked, true),
            'connectHref' => route('social.connect', $provider->key()),
            'disconnectHref' => route('account.social.destroy', $provider->key()),
        ], $providers->all());
    }

    /**
     * The enrolment `otpauth://` URI as a QR code — what an authenticator app or password
     * manager scans.
     *
     * A `data:` URI rather than inline SVG markup. The blade injected the SVG into the
     * document, which under React means `dangerouslySetInnerHTML` on a page that shows a
     * credential: safe here, because the markup is generated from a URI we minted and never
     * from anything a request supplied — but "safe because of where it came from" is an
     * argument that survives exactly until somebody moves the call. An `<img>` cannot
     * execute what it is given, so the question does not arise.
     *
     * It is drawn on a white plate either way: a QR code has to be dark-on-light to scan,
     * so this is the one thing on the page that does not follow the theme.
     */
    private function qrCode(string $provisioningUri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($provisioningUri));
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

    /**
     * A validated "return to your app" target, when this page was reached through a
     * client-SDK profile redirect (`?return_to=`).
     *
     * Only a well-formed absolute https URL (or http on localhost) is offered, only to a
     * host this environment actually redirects to, and only as a clickable link — never an
     * automatic redirect. Without the host allow-list, `?return_to=https://evil.tld` renders
     * a legitimate-looking "Return to …" link: an open-redirect and phishing pivot on an IdP
     * surface, which is the worst place in the product to have one.
     *
     * @return array{url: string, host: string}|null
     */
    private function validReturnTo(Request $request): ?array
    {
        $url = $request->query('return_to');

        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $isLocal = in_array($parts['host'], ['localhost', '127.0.0.1'], true);

        if ($parts['scheme'] !== 'https' && ! ($isLocal && $parts['scheme'] === 'http')) {
            return null;
        }

        // Client is environment-scoped, so this is the current realm's registered set.
        /** @var Collection<int, string> $redirectHosts */
        $redirectHosts = Client::query()
            ->get(['redirect_uris'])
            ->flatMap(fn (Client $client): array => $client->redirect_uris)
            ->map(static fn (string $uri): ?string => parse_url($uri, PHP_URL_HOST) ?: null)
            ->filter();

        $allowedHosts = $redirectHosts->push($request->getHost())->unique();

        if (! $allowedHosts->contains($parts['host'])) {
            return null;
        }

        return ['url' => $url, 'host' => $parts['host']];
    }
}
