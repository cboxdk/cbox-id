<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use Cbox\Id\FrontendApi\Models\PublishableKey;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * The WebAuthn challenge, carried by a token instead of by a session cookie.
 *
 * A passkey sign-in is two requests: one that hands out a random challenge, and one that
 * returns the authenticator's signature over it. The hosted form keeps the challenge in
 * the session between them; a page on somebody else's origin has no session cookie, so the
 * challenge has to travel — as an opaque handle, never as something the caller can forge.
 *
 * A CACHE RATHER THAN A TABLE, which is the opposite of the login tickets beside it, and
 * deliberately so. A ticket records that a credential check happened — a fact worth
 * keeping, worth auditing, and worth being able to explain during an incident. A challenge
 * is a nonce with no meaning after two minutes and no meaning to anybody: a row that must
 * vanish is a row somebody has to sweep, and the sweep is the part that gets forgotten.
 *
 * SINGLE USE BY CONSTRUCTION. `pull()` reads and forgets in one operation, so two requests
 * racing the same handle cannot both receive the challenge — which matters because a
 * replayed challenge is a replayed assertion.
 */
final class PasskeyChallenges
{
    /**
     * Two minutes.
     *
     * Long enough for somebody to find their phone, touch a sensor, and answer a prompt
     * they were not expecting; short enough that a handle left in a log is worthless.
     */
    private const TTL_SECONDS = 120;

    public function __construct(private readonly Cache $cache) {}

    /**
     * Store a challenge and return the handle that fetches it back.
     *
     * The handle is bound to the key that asked for it, so a challenge issued to one
     * customer's page cannot be answered from another's — both may hold valid keys against
     * this environment, and the environment alone is not a boundary between them.
     */
    public function issue(PublishableKey $key, string $challenge): string
    {
        $handle = Str::random(32);

        $this->cache->put($this->cacheKey($handle), [
            'challenge' => base64_encode($challenge),
            'key' => $key->id,
        ], self::TTL_SECONDS);

        return $handle;
    }

    /**
     * Take the challenge back, once.
     *
     * Returns null for everything the caller must treat identically — unknown handle,
     * expired, already used, or issued to a different key. A replayed handle and one that
     * never existed are the same answer, because distinguishing them tells somebody which
     * of their guesses was once real.
     */
    public function claim(PublishableKey $key, string $handle): ?string
    {
        /** @var array{challenge?: string, key?: string}|null $stored */
        $stored = $this->cache->pull($this->cacheKey($handle));

        if (! is_array($stored) || ! is_string($stored['challenge'] ?? null)) {
            return null;
        }

        if (($stored['key'] ?? null) !== $key->id) {
            return null;
        }

        $challenge = base64_decode($stored['challenge'], true);

        return $challenge === false ? null : $challenge;
    }

    private function cacheKey(string $handle): string
    {
        // Hashed, so a cache dump does not hand somebody a working handle — the same
        // reasoning as hashing a login ticket, applied to a shorter-lived thing.
        return 'cbox-frontend-passkey:'.hash('sha256', $handle);
    }
}
