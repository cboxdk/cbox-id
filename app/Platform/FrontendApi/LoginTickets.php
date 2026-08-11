<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use Cbox\Id\FrontendApi\Models\PublishableKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minting and redeeming the ticket an embedded sign-in form gets instead of tokens.
 *
 * The whole reason this type exists rather than the endpoint doing it inline: redemption
 * has to be ATOMIC. A ticket that can be redeemed twice is a session an attacker gets for
 * free by replaying a URL out of somebody's history, and "read it, check it, mark it" in
 * three statements is exactly the race that allows it under any concurrency.
 */
final class LoginTickets
{
    /** Long enough to cross one redirect, short enough that a URL in history is stale. */
    private const TTL_SECONDS = 60;

    /** 32 base62 — full entropy, so the hash below can be a plain sha256. */
    private const ENTROPY = 32;

    /**
     * Mint a ticket for a completed credential check.
     *
     * Returns the plaintext, which is the only time it exists in that form; the row keeps
     * a hash. `$amr` is what the check actually established and travels with the ticket,
     * so a session created this way is indistinguishable from one created at the hosted
     * form — an embedded passkey sign-in must not produce a weaker `acr` than a hosted
     * one, and it would if the methods were dropped here and re-guessed later.
     *
     * @param  list<string>  $amr
     */
    public function mint(PublishableKey $key, string $subjectId, array $amr): string
    {
        $token = Str::random(self::ENTROPY);

        LoginTicket::query()->create([
            'token_hash' => hash('sha256', $token),
            'publishable_key_id' => $key->id,
            'subject_id' => $subjectId,
            'amr' => $amr,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $token;
    }

    /**
     * Redeem a ticket, once.
     *
     * Returns the ticket on success and null for every failure a caller must treat the
     * same: unknown, expired, already redeemed, or belonging to another environment. The
     * caller turns all of them into "you are not signed in", because telling them apart
     * would let somebody probe which tickets ever existed.
     *
     * THE UPDATE IS THE CHECK. A conditional `UPDATE … WHERE redeemed_at IS NULL` that
     * affects one row is the only reader that won; a second attempt affects zero. Reading
     * first and marking afterwards would leave a window in which two requests both see an
     * unredeemed ticket, and two sessions is one more than a password bought.
     */
    public function redeem(string $token): ?LoginTicket
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash): ?LoginTicket {
            $claimed = LoginTicket::query()
                ->where('token_hash', $hash)
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->update(['redeemed_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            $ticket = LoginTicket::query()->where('token_hash', $hash)->first();

            return $ticket instanceof LoginTicket ? $ticket : null;
        });
    }

    /**
     * Drop tickets nobody redeemed.
     *
     * Called from the scheduler beside the other sweeps. Expired rows are harmless — they
     * cannot be redeemed — but a table that only grows is one somebody eventually finds
     * during an incident and cannot read.
     */
    public function prune(): int
    {
        /** @var int $deleted */
        $deleted = LoginTicket::query()->where('expires_at', '<', now()->subHour())->delete();

        return $deleted;
    }
}
