<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Where a {@see PendingLink} is kept between the provider's callback and the person's
 * answer: the server-side session, signed.
 *
 * One hold per browser. A second social sign-in that lands on the step replaces the first,
 * and the page's `id` is what stops an answer meant for the first from completing the
 * second.
 *
 * WHY SIGN SOMETHING THE BROWSER NEVER SEES. Because "server-side" is not the same as
 * "only written by this class". The session is a row in a database or a key in a cache
 * that the rest of the application also writes, and the payload names an ACCOUNT. A hold
 * whose subject id had been changed underneath it would link somebody's Google to an
 * account they never proved anything about. The HMAC is over the whole payload with the
 * application key, so the only holds that read back are the ones this class wrote — and
 * anything else is treated as no hold, the same as an expired one.
 */
final readonly class LinkHold
{
    public const SESSION_KEY = 'cbox.link_hold';

    public function __construct(private Encrypter $encrypter) {}

    public function put(PendingLink $link): void
    {
        $payload = $link->toArray();

        session()->put(self::SESSION_KEY, [
            'payload' => $payload,
            'signature' => $this->sign($payload),
        ]);
    }

    /**
     * The live hold, or null when there is none, it has expired, or it does not verify.
     *
     * Expiry is enforced on READ: the session is the only place the hold lives, so nothing
     * else would ever come along to clear it.
     */
    public function current(): ?PendingLink
    {
        $stored = session()->get(self::SESSION_KEY);

        if (! is_array($stored) || ! is_array($stored['payload'] ?? null) || ! is_string($stored['signature'] ?? null)) {
            $this->forget();

            return null;
        }

        if (! hash_equals($this->sign($stored['payload']), $stored['signature'])) {
            $this->forget();

            return null;
        }

        $link = PendingLink::fromArray($stored['payload']);

        if ($link === null || $link->expired(now()->getTimestamp())) {
            $this->forget();

            return null;
        }

        return $link;
    }

    /**
     * Take the hold for good, but only if it is the one the page was drawn for.
     *
     * Pulled BEFORE anything is linked, so two answers racing each other cannot both
     * complete: the second finds nothing.
     */
    public function pull(string $id): ?PendingLink
    {
        $link = $this->current();

        if ($link === null || ! hash_equals($link->id, $id)) {
            return null;
        }

        $this->forget();

        return $link;
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function sign(array $payload): string
    {
        // A purpose prefix, so this MAC can never be mistaken for one the application key
        // signs for another reason.
        return hash_hmac('sha256', 'cbox-id:link-hold:'.json_encode($payload, JSON_THROW_ON_ERROR), $this->encrypter->getKey());
    }
}
