<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\AccountLinking\AccountLinking;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\AccountLinking\LinkHold;
use App\Platform\AccountLinking\LinkProof;
use App\Platform\AccountLinking\PendingLink;
use App\Platform\FederatedLanding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * THE LINK STEP — "Link your Google account to sn@cbox.dk?", and the answer that proves it.
 *
 * Hosted, branded and translated like every other step of signing in, and in the same
 * in-between state as the second-factor challenge: the provider has vouched for somebody,
 * no session exists yet, and everything about WHO is being linked to WHAT lives in the
 * session, signed ({@see LinkHold}). The page posts back only
 * the hold's id, which method it is answering with, and the answer — there is no field
 * here that can point the step at a different account or a different identity.
 *
 * The rules — what counts as proof, what a Radar challenge withdraws, what a mandate
 * refuses — are {@see AccountLinking}'s. This class is the page and its three answers.
 */
final readonly class LinkAccountController extends PageController
{
    /** Wrong answers per account per minute, on top of the hold's own budget. */
    private const ATTEMPTS_PER_MINUTE = 5;

    /** Codes a person may ask for per account per minute — the button, not the mailbox. */
    private const SENDS_PER_MINUTE = 3;

    public function show(AccountLinking $linking): Response|RedirectResponse
    {
        $link = $linking->pending();

        // Nothing held — the link expired, was answered in another tab, or the URL was
        // typed. Back to signing in, saying so rather than drawing an empty step.
        if ($link === null) {
            return app(FederatedLanding::class)->failed(FederationFailure::LinkExpired);
        }

        return $this->page('auth/link-account', __('auth.link_account.title', ['provider' => $link->providerLabel]), [
            'hold' => $link->id,
            'provider' => [
                'key' => $link->providerKey,
                'label' => $link->providerLabel,
            ],
            // Unmasked, and deliberately: the person reached this page only by signing in
            // at the provider AS this address, and the whole question is about it.
            'email' => $link->accountEmail,
            'factors' => $linking->factors($link),
            'codeSent' => $link->codeSent,
            // A Radar challenge took the password off the table; the page says why the
            // choice is narrower than it would be, without saying what Radar saw.
            'challenged' => $link->challenged,
        ]);
    }

    public function verify(Request $request, AccountLinking $linking): RedirectResponse
    {
        $request->validate([
            'hold' => ['required', 'string', 'max:64'],
            'method' => ['required', Rule::enum(LinkProof::class)->except([LinkProof::SignedIn])],
            'answer' => ['required', 'string', 'max:255'],
        ]);

        $link = $this->heldFor($request->string('hold')->toString(), $linking);

        if ($link === null) {
            return app(FederatedLanding::class)->failed(FederationFailure::LinkExpired);
        }

        $key = 'link-at-sign-in|'.$link->subjectId;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            return back()->withErrors([
                'answer' => trans_choice('auth.common.too_many_attempts', RateLimiter::availableIn($key)),
            ]);
        }

        $proof = LinkProof::from($request->string('method')->toString());
        // Codes are typed with spaces and dashes; a password is never touched.
        $typed = $request->string('answer')->toString();
        $answer = $proof === LinkProof::Password ? $typed : (string) preg_replace('/[\s-]+/', '', $typed);

        if (! $linking->prove($request, $link, $proof, $answer)) {
            RateLimiter::hit($key, 60);

            // The fifth wrong answer took the hold with it.
            if ($linking->pending() === null) {
                return app(FederatedLanding::class)->failed(FederationFailure::LinkAttemptsExhausted, ['provider' => $link->providerLabel]);
            }

            return back()->withErrors([
                'answer' => __($proof === LinkProof::Password ? 'auth.link_account.wrong_password' : 'auth.common.code_incorrect'),
            ]);
        }

        RateLimiter::clear($key);

        return $linking->complete($request, $link->id, $proof);
    }

    /** "Email me a code" / "Text me a code". */
    public function send(Request $request, AccountLinking $linking): RedirectResponse
    {
        $request->validate([
            'hold' => ['required', 'string', 'max:64'],
            'channel' => ['required', Rule::in(['email', 'sms'])],
        ]);

        $link = $this->heldFor($request->string('hold')->toString(), $linking);

        if ($link === null) {
            return app(FederatedLanding::class)->failed(FederationFailure::LinkExpired);
        }

        $factors = $linking->factors($link);
        $channel = $request->string('channel')->toString() === 'sms' ? 'sms' : 'email';

        if (! $factors[$channel]) {
            return back();
        }

        $key = 'link-at-sign-in-send|'.$link->subjectId;

        if (RateLimiter::tooManyAttempts($key, self::SENDS_PER_MINUTE)) {
            return back()->withErrors([
                'answer' => trans_choice('auth.common.too_many_requests', RateLimiter::availableIn($key)),
            ]);
        }

        RateLimiter::hit($key, 60);

        if ($channel === 'sms') {
            $sent = $linking->sendSms($request, $link);

            if ($sent === null) {
                return back()->withErrors(['answer' => __('auth.link_account.sms_failed')]);
            }

            $this->inertia->flash('linkCodeSent', __('auth.link_account.sms_sent', ['number' => $sent->maskedNumber]));

            return back();
        }

        if ($linking->sendEmailCode($request, $link) === null) {
            return back()->withErrors(['answer' => __('auth.otp_step_up.too_many_codes')]);
        }

        $this->inertia->flash('linkCodeSent', __('auth.link_account.email_sent', ['email' => $link->accountEmail]));

        return back();
    }

    /** "Don't link" — nothing changes, and the person is back at the sign-in page. */
    public function cancel(AccountLinking $linking): RedirectResponse
    {
        $linking->cancel();

        return to_route('login')->with('status', __('auth.link_account.cancelled'));
    }

    /**
     * The live hold, if it is the one this page was drawn for.
     *
     * A page left open for a link that has since been REPLACED (a second sign-in with the
     * provider, in another tab) answers nothing: its id no longer matches.
     */
    private function heldFor(string $id, AccountLinking $linking): ?PendingLink
    {
        $link = $linking->pending();

        return $link !== null && hash_equals($link->id, $id) ? $link : null;
    }
}
