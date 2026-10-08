<?php

declare(strict_types=1);

namespace App\Http;

use App\Platform\PlaneResolver;
use App\Platform\ThrottleScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;

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
 *     assertion half is a credential check like any other.
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

    /** Passkey ceremonies (challenge + assertion) per minute from one address. */
    public const PASSKEY_PER_IP = 30;

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
            fn (Request $request): Limit => self::passkeyLimit($request),
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

    private static function passkeyLimit(Request $request): Limit
    {
        // A CORS preflight is answered by the Frontend API's own middleware before it can
        // reach anything that mints a challenge, and a browser sends one ahead of every
        // real request. Counting it would halve the budget for the very callers the
        // limit is not aimed at.
        if ($request->isMethod('OPTIONS')) {
            return Limit::none();
        }

        return Limit::perMinute(self::PASSKEY_PER_IP)->by(self::scope($request));
    }

    /** Route, environment and client address — the bucket every limit here starts from. */
    private static function scope(Request $request): string
    {
        $route = $request->route();

        return implode('|', [
            ($route instanceof Route ? $route->getName() : null) ?? $request->path(),
            ThrottleScope::key(),
            'ip:'.($request->ip() ?? 'unknown'),
        ]);
    }
}
