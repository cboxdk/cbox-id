<?php

declare(strict_types=1);

namespace App\Http\Controllers\FrontendApi;

use App\Platform\Enums\AttemptOutcome;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Identity\Contracts\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing in from a page that drew its own form.
 *
 * This is the endpoint that makes an embedded sign-in box possible, and it is the most
 * dangerous surface in the product: an unauthenticated caller offering a password, from a
 * browser, cross-origin. Everything below is a consequence of that.
 *
 * IT HANDS BACK A TICKET, NEVER A TOKEN. Giving tokens to a page that proved a password is
 * the implicit grant, which OAuth 2.1 removes: tokens in a URL, in history, in `Referer`,
 * with no client authentication and no PKCE binding. Instead the page receives a
 * single-use 60-second ticket and walks the ordinary `/oauth/authorize` flow with its own
 * PKCE challenge. `/authorize` remains the only thing that issues a code.
 *
 * IT REUSES THE HOSTED FORM'S BRAIN. {@see PlatformAuth::attemptPassword()} is what the
 * hosted page calls, and it carries the lockout counter, the breach check, the SSO-required
 * policy, the MFA branch and the audit entries. A second implementation here would be a
 * second set of those, and the one that got fewer of them would be the one attackers used.
 *
 * IT SAYS AS LITTLE AS POSSIBLE. A wrong password, an unknown address and a locked account
 * are one answer, because distinguishing them is the enumeration oracle every identity
 * product eventually leaks — and a public endpoint is the easiest place to leak it.
 */
class SignInController
{
    /**
     * Attempts per minute for one email address, across every caller.
     *
     * The channel's own limiter is per publishable KEY, which is the wrong unit here: an
     * attacker spreading guesses across many pages holding the same key would sit under
     * it, and one spreading across many keys would evade it entirely. This one is keyed by
     * the address being attacked, which is the thing being defended.
     *
     * Deliberately tighter than a human needs and looser than the account lockout, so the
     * lockout stays the control that matters and this is only a brake on volume.
     */
    private const PER_EMAIL_PER_MINUTE = 10;

    public function __construct(
        private readonly PlatformAuth $auth,
        private readonly LoginTickets $tickets,
        private readonly SessionManager $sessions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->attributes->get('cbox_publishable_key');

        if (! $key instanceof PublishableKey) {
            // Unreachable behind the channel's middleware; asserted rather than assumed,
            // because this controller must never run without knowing which key admitted it.
            return $this->refuse();
        }

        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return $this->refuse();
        }

        $limiter = 'cbox-frontend-signin:'.hash('sha256', mb_strtolower($email));

        if (RateLimiter::tooManyAttempts($limiter, self::PER_EMAIL_PER_MINUTE)) {
            // 429 rather than the generic refusal: a legitimate person hitting this needs
            // to be told to wait, and an attacker learns only that the address is rate
            // limited — which is true of every address, so it discloses nothing.
            return new JsonResponse([
                'status' => 'rate_limited',
                'retry_after' => RateLimiter::availableIn($limiter),
            ], 429);
        }

        RateLimiter::hit($limiter);

        $outcome = $this->auth->attemptPassword($request, $email, $password);

        return match ($outcome) {
            AttemptOutcome::Ok => $this->ticketFor($key, $request),

            // The person exists and the password was right; the flow is not finished. The
            // page is told which challenge to draw and NOTHING about who it is drawing it
            // for — the pending subject stays in the session `PlatformAuth` already put it
            // in, so no identifier for it crosses this channel.
            // The pending state travels as a token, because a cross-origin page carries
            // no session cookie to the next request. It names the subject and nothing a
            // caller can read — see the ticket table.
            AttemptOutcome::Mfa => $this->pending($key, $request, 'pending_mfa', 'mfa_required'),
            AttemptOutcome::Otp => $this->pending($key, $request, 'pending_otp', 'otp_required'),

            // A tenant mandates single sign-on, so there is no second step here at all —
            // the page must send them to their identity provider. Named, because a page
            // that shows "wrong password" to somebody whose organization requires SSO
            // sends them to support instead of to their IdP.
            AttemptOutcome::SsoRequired => new JsonResponse(['status' => 'sso_required']),

            AttemptOutcome::Invalid => $this->refuse(),
        };
    }

    /**
     * Mint the ticket for a completed sign-in.
     *
     * The session `PlatformAuth` established is deliberately not what the page carries
     * away: a cross-origin page cannot rely on that cookie, and building the flow on one
     * would mean requiring `SameSite=None` on the session — weakening CSRF posture for
     * every hosted page in order to serve embedded ones.
     */
    private function ticketFor(PublishableKey $key, Request $request): JsonResponse
    {
        // Read from the session `attemptPassword()` just established, rather than from a
        // second lookup: it is the authority on what the check produced, including the
        // `amr` a passkey or a second factor contributed.
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);
        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            return $this->refuse();
        }

        /** @var list<string> $amr */
        $amr = array_values(array_filter($session->amr, is_string(...)));

        return new JsonResponse([
            'status' => 'ok',
            'login_ticket' => $this->tickets->mint($key, $session->user_id, $amr),
            // Said out loud so an SDK does not have to know it: the ticket is for one
            // redirect, not for storing.
            'expires_in' => 60,
        ]);
    }

    /**
     * Mint the token that carries a half-finished sign-in to the next request.
     */
    private function pending(PublishableKey $key, Request $request, string $stage, string $status): JsonResponse
    {
        $subjectId = $stage === 'pending_otp'
            ? ($this->auth->pendingOtpStepUp($request)['subject'] ?? null)
            : $this->auth->pendingMfaSubject($request);

        if (! is_string($subjectId)) {
            return $this->refuse();
        }

        return new JsonResponse([
            'status' => $status,
            'mfa_token' => $this->tickets->mintPending($key, $subjectId, ['pwd'], $stage),
            'expires_in' => 300,
        ]);
    }

    /**
     * One answer for a wrong password, an unknown address and a locked account.
     *
     * 401 with no detail. Telling them apart is the enumeration oracle, and the timing is
     * already equalised inside `attemptPassword()`, which does the same hashing work for
     * an address it has never seen.
     */
    private function refuse(): JsonResponse
    {
        return new JsonResponse(['status' => 'invalid'], 401);
    }
}
