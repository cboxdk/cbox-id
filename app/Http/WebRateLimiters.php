<?php

declare(strict_types=1);

namespace App\Http;

use App\Platform\CurrentUser;
use App\Platform\PlaneResolver;
use App\Platform\ThrottleScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Rate limiting for the browser doors that take a secret from the URL or hand out a
 * WebAuthn challenge to anybody who asks.
 *
 * Every one of these was unmetered. Some of them throttle in their service — a password
 * guess, an MFA code, an invitation resend — but the routes below had nothing at all:
 *
 *   - the single-use LINK doors (`magic/{token}`, `setup/{token}`, `invite/{token}/accept`,
 *     `verify-email/{token}`) look a presented token up by hash on every request. The token
 *     space is far too large to guess, so this is not what makes them safe; what was missing
 *     is the ceiling that turns a guessing script or a replay loop from "as fast as the
 *     database answers" into something that shows up in a log and costs the caller time;
 *   - the passkey CEREMONY doors (`passkeys/login/options`, `passkeys/register/options`, the
 *     hosted `passkeys/login`, and the Frontend API's two `sign-in/passkey` requests) mint a
 *     random challenge and write it to the session or the cache on every call, for an
 *     anonymous caller. Unmetered, that is a free way to fill a session store, and the
 *     assertion half is a credential check like any other;
 *   - the doors that SEND MAIL or CLAIM THE DEPLOYMENT for whoever asks: the sign-in link
 *     request (`login/magic-link`), the signup confirmation resend
 *     (`projects/verification/resend`) and the first-run claim (`first-run`). Each already
 *     answers its own friendly "try again in a minute" from inside the controller or the
 *     action — that is the sentence a person reads. The limiter here is the ceiling above it,
 *     in front of the controller, answering a 429 with `Retry-After` to a script that ignores
 *     the sentence and keeps posting.
 *
 * Every named limiter here answers a 429 with a `Retry-After` header (Laravel's
 * `ThrottleRequests`), which is what a well-behaved client backs off on.
 *
 * ── On the KEY ──────────────────────────────────────────────────────────────────────
 * Every key starts with the ROUTE NAME and {@see ThrottleScope::key()}. The route name
 * because one limiter serves several doors and a sign-in link spent must not eat the
 * budget of an invitation; the environment because a multi-tenant deployment serves every
 * tenant from one cache, and an office NAT exhausting one tenant's bucket must not lock
 * that office out of every other tenant (ThrottleScope's docblock tells that story in full).
 *
 * The link doors carry TWO limits, the same shape {@see ApiRateLimiters} uses:
 *
 *   1. per (IP, token) — tight. A person presses the button once; a few retries over a
 *      flaky connection is the most a real link ever sees.
 *   2. per IP — laxer, and the one that actually binds a guesser. A token-keyed bucket
 *      alone would hand a script iterating over made-up tokens a fresh budget per guess.
 *
 * The token is fingerprinted, never used raw: limiter keys reach the cache store, and a
 * live single-use credential has no business being a cache key — even though Laravel
 * hashes named-limiter keys again on the way in.
 *
 * Budgets are constants, not config. They are generous enough that no legitimate flow
 * comes near them, and a knob nobody has a reason to turn is a knob somebody turns to
 * zero during an incident and forgets.
 */
final class WebRateLimiters
{
    /** Single-use mailed links: presses per minute of ONE token from one address. */
    public const LINK_PER_TOKEN = 10;

    /** Single-use mailed links: presses per minute of ANY token from one address. */
    public const LINK_PER_IP = 30;

    /**
     * Passkey ceremonies (challenge + assertion) per minute from one address — and, for the
     * enrolment challenge a signed-in person asks for, per person as well.
     */
    public const PASSKEY_PER_IP = 30;

    /** Sign-in link requests per minute for ONE address typed in, from one client address. */
    public const MAGIC_LINK_PER_EMAIL = 5;

    /** Sign-in link requests per minute for ANY address typed in, from one client address. */
    public const MAGIC_LINK_PER_IP = 20;

    /** Signup confirmation resends per minute for one person, from one client address. */
    public const VERIFICATION_RESEND_PER_PERSON = 5;

    /**
     * First-run claims per minute from one address. The setup token is the whole credential
     * for an unclaimed deployment; the controller locks a guesser out after a handful of
     * wrong tokens, and this is the ceiling in front of it.
     */
    public const FIRST_RUN_PER_IP = 10;

    /**
     * `/oauth/authorize` and its consent steps at the PLATFORM ROOT, per minute from one
     * address. A person signing an MCP client in makes a handful of these; an MCP client
     * registered by anyone can send anyone there, so the root meters it. Everywhere else the
     * endpoint is a tenant's identity provider and unmetered as before — its relying parties
     * run silent renew through it and a NAT of their users is not an attacker.
     */
    public const ROOT_AUTHORIZE_PER_IP = 60;

    public static function register(): void
    {
        RateLimiter::for(
            'link-token',
            /** @return list<Limit> */
            fn (Request $request): array => self::linkLimits($request),
        );

        RateLimiter::for(
            'passkey',
            /** @return list<Limit> */
            fn (Request $request): array => self::passkeyLimits($request),
        );

        RateLimiter::for(
            'magic-link-send',
            /** @return list<Limit> */
            fn (Request $request): array => self::magicLinkLimits($request),
        );

        RateLimiter::for(
            'verification-resend',
            fn (Request $request): Limit => Limit::perMinute(self::VERIFICATION_RESEND_PER_PERSON)
                ->by(self::scope($request).'|subject:'.(app(CurrentUser::class)->check() ? app(CurrentUser::class)->id() : 'guest')),
        );

        RateLimiter::for(
            'first-run',
            fn (Request $request): Limit => Limit::perMinute(self::FIRST_RUN_PER_IP)->by(self::scope($request)),
        );

        RateLimiter::for(
            'oauth-authorize',
            fn (Request $request): Limit => app(PlaneResolver::class)->onAccountPlane()
                ? Limit::perMinute(self::ROOT_AUTHORIZE_PER_IP)->by('oauth-authorize|'.ThrottleScope::key().'|ip:'.($request->ip() ?? 'unknown'))
                : Limit::none(),
        );
    }

    /**
     * @return list<Limit>
     */
    private static function linkLimits(Request $request): array
    {
        $scope = self::scope($request);
        $token = $request->route('token');
        $fingerprint = is_string($token) ? substr(hash('sha256', $token), 0, 32) : 'none';

        return [
            Limit::perMinute(self::LINK_PER_TOKEN)->by($scope.'|token:'.$fingerprint),
            Limit::perMinute(self::LINK_PER_IP)->by($scope),
        ];
    }

    /**
     * Per address — and, where somebody is signed in (the enrolment challenge), per person
     * too: a person's own session is the identifier there, so one account cannot spend a
     * shared office address's budget for everybody behind it, nor rotate addresses to mint
     * enrolment challenges without end. The sign-in ceremony has no identifier before the
     * assertion — a discoverable credential names nobody — so the address is its key.
     *
     * @return list<Limit>
     */
    private static function passkeyLimits(Request $request): array
    {
        // A CORS preflight is answered by the Frontend API's own middleware before it can
        // reach anything that mints a challenge, and a browser sends one ahead of every
        // real request. Counting it would halve the budget for the very callers the
        // limit is not aimed at.
        if ($request->isMethod('OPTIONS')) {
            return [Limit::none()];
        }

        $limits = [Limit::perMinute(self::PASSKEY_PER_IP)->by(self::scope($request))];
        $current = app(CurrentUser::class);

        if ($current->check()) {
            $limits[] = Limit::perMinute(self::PASSKEY_PER_IP)->by(self::routeScope($request).'|subject:'.$current->id());
        }

        return $limits;
    }

    /**
     * Per (address, email typed in) — tight, a person asks for a link once or twice — and per
     * address, laxer, which is the one that binds a script cycling through addresses. The
     * email is fingerprinted, never used raw: it is a person's address on its way to a
     * cache key.
     *
     * @return list<Limit>
     */
    private static function magicLinkLimits(Request $request): array
    {
        $email = Str::lower(trim($request->string('email')->toString()));

        return [
            Limit::perMinute(self::MAGIC_LINK_PER_EMAIL)->by(self::scope($request).'|email:'.substr(hash('sha256', $email), 0, 32)),
            Limit::perMinute(self::MAGIC_LINK_PER_IP)->by(self::scope($request)),
        ];
    }

    /** Route, environment and client address — the bucket every limit here starts from. */
    private static function scope(Request $request): string
    {
        return self::routeScope($request).'|ip:'.($request->ip() ?? 'unknown');
    }

    /** Route and environment, for a limit keyed on who rather than where from. */
    private static function routeScope(Request $request): string
    {
        $route = $request->route();

        return implode('|', [
            ($route instanceof Route ? $route->getName() : null) ?? $request->path(),
            ThrottleScope::key(),
        ]);
    }
}
