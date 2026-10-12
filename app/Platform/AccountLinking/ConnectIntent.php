<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\FederatedLanding;

/**
 * "This trip to Google is to CONNECT it, not to sign in" — said before the browser leaves,
 * read when it comes back.
 *
 * WHY IT IS NEEDED. An environment's Google has exactly one redirect URI registered with
 * Google: `/sso/oidc/{connection}/callback`. Connecting it from My account has to come back
 * through that same URI, because a second one would mean every administrator registering
 * two addresses with every provider — and the callback cannot tell a connect from a
 * sign-in by looking at what Google sent. So the browser says so first, here, and the
 * callback asks.
 *
 * WHAT BINDS IT. The connection (a connect started for Google is not completed by a
 * GitHub callback), the person who started it, and the browser session they started it
 * in: the callback links to the account that is STILL signed in, and only if that is the
 * same account. Ten minutes, single use — pulled on the callback that claims it, and
 * dropped by every failure on the way ({@see FederatedLanding::failed()}).
 */
final class ConnectIntent
{
    private const SESSION_KEY = 'cbox.connect_intent';

    private const TTL_SECONDS = 600;

    public static function start(string $connectionId, string $subjectId, string $sessionId): void
    {
        session()->put(self::SESSION_KEY, [
            'connection_id' => $connectionId,
            'subject_id' => $subjectId,
            'session_id' => $sessionId,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * Take the intent for this connection, if there is a live one.
     *
     * An intent for a DIFFERENT connection is left alone: somebody who started connecting
     * GitHub and then signed in with Google in another tab has not abandoned the first.
     *
     * @return array{subject_id: string, session_id: string}|null
     */
    public static function claim(string $connectionId): ?array
    {
        $intent = session()->get(self::SESSION_KEY);

        if (! is_array($intent)
            || ! is_string($intent['connection_id'] ?? null)
            || ! is_string($intent['subject_id'] ?? null)
            || ! is_string($intent['session_id'] ?? null)
            || ! is_int($intent['at'] ?? null)
        ) {
            self::forget();

            return null;
        }

        if ($intent['connection_id'] !== $connectionId) {
            return null;
        }

        self::forget();

        if (now()->getTimestamp() - $intent['at'] >= self::TTL_SECONDS) {
            return null;
        }

        return ['subject_id' => $intent['subject_id'], 'session_id' => $intent['session_id']];
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
