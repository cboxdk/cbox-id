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

    /**
     * A pending second factor gets longer, because a person has to read a code off a phone.
     *
     * Five minutes is the window the hosted form's challenge already effectively has, and
     * matching it means an embedded sign-in is not mysteriously stricter than the page it
     * replaces.
     */
    private const PENDING_TTL_SECONDS = 300;

    /**
     * Wrong codes a pending ticket survives.
     *
     * Not one: somebody mistypes six digits, and making that cost them their password as
     * well is a worse product for no security. Not many either — the bound is what stops
     * the ticket becoming something to brute-force a six-digit space against.
     */
    private const MAX_ATTEMPTS = 5;

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
        return $this->issue($key, $subjectId, $amr, 'ready', self::TTL_SECONDS);
    }

    /**
     * Mint a ticket that is NOT yet a sign-in, because a factor is still owed.
     *
     * It carries the same subject and key as the ready ticket it will become, so nothing
     * has to be copied between two records that could then disagree.
     *
     * @param  list<string>  $amr  what the first factor established
     */
    public function mintPending(PublishableKey $key, string $subjectId, array $amr, string $stage): string
    {
        return $this->issue($key, $subjectId, $amr, $stage, self::PENDING_TTL_SECONDS);
    }

    /**
     * The pending ticket this token names, with one attempt counted against it.
     *
     * COUNTING HAPPENS HERE, before the code is checked, and that ordering is the point: a
     * caller that crashes the verifier, times out, or simply walks away must still have
     * spent an attempt, or the bound is not a bound.
     *
     * Returns null once the attempts are gone, which the caller treats exactly as a wrong
     * code — telling them apart would say "that ticket was real, you just ran out".
     */
    public function claimAttempt(string $token, string $stage, PublishableKey $key): ?LoginTicket
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $stage, $key): ?LoginTicket {
            $claimed = LoginTicket::query()
                ->where('token_hash', $hash)
                ->where('stage', $stage)
                // THE KEY IS PART OF THE CLAIM, not a check afterwards. Both are in the
                // same environment, so both reach this endpoint — and a token issued for
                // one customer's page, replayed five times through another's key, used to
                // burn the attempts before the ownership check refused it, leaving the real
                // person unable to finish their own second factor.
                ->where('publishable_key_id', $key->id)
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->increment('attempts');

            if ($claimed !== 1) {
                return null;
            }

            $ticket = LoginTicket::query()->where('token_hash', $hash)->first();

            return $ticket instanceof LoginTicket ? $ticket : null;
        });
    }

    /**
     * Turn a pending ticket into a ready one, once the factor is satisfied.
     *
     * The SAME row is promoted rather than a second minted: a pending ticket that survived
     * its own promotion would be a second chance at a factor that has already been used.
     *
     * @param  list<string>  $amr  the methods now established, including the second factor
     */
    public function promote(LoginTicket $ticket, array $amr): string
    {
        $token = Str::random(self::ENTROPY);

        $ticket->update([
            'token_hash' => hash('sha256', $token),
            'stage' => 'ready',
            'amr' => $amr,
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $token;
    }

    /**
     * @param  list<string>  $amr
     */
    private function issue(PublishableKey $key, string $subjectId, array $amr, string $stage, int $ttl): string
    {
        $token = Str::random(self::ENTROPY);

        LoginTicket::query()->create([
            'token_hash' => hash('sha256', $token),
            'publishable_key_id' => $key->id,
            'subject_id' => $subjectId,
            'stage' => $stage,
            'amr' => $amr,
            'expires_at' => now()->addSeconds($ttl),
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
    public function redeem(string $token, string $environmentKey): ?LoginTicket
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $environmentKey): ?LoginTicket {
            $claimed = LoginTicket::query()
                ->where('token_hash', $hash)
                // THE ENVIRONMENT IS PART OF THE CLAIM, not a check on the row afterwards.
                //
                // It used to be checked by the caller, on the ticket this method returned.
                // That check could never fail: the model is environment-scoped, so a
                // foreign ticket was already invisible here and redemption answered null
                // one step earlier. The caller's guard was unreachable, its test passed
                // with the guard deleted, and the protection everything downstream assumed
                // was really the ambient scope — which one `withoutScope()` anywhere in a
                // future call path would remove without a single test going red.
                ->where('environment_id', $environmentKey)
                // `ready` only: a ticket still owing a second factor is not a sign-in, and
                // redeeming one would be the bypass this whole stage exists to prevent.
                ->where('stage', 'ready')
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->update(['redeemed_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            $ticket = LoginTicket::query()
                ->where('token_hash', $hash)
                ->where('environment_id', $environmentKey)
                ->first();

            return $ticket instanceof LoginTicket ? $ticket : null;
        });
    }

    /**
     * The subject a ticket named, whether or not it is still redeemable.
     *
     * FOR TELLING A REPLAY FROM A REFRESH. `redeem()` answers null for both — it cannot do
     * otherwise, the conditional UPDATE is what makes it single-use — but the caller has
     * to distinguish them: a ticket already spent BY THIS BROWSER, for the person now
     * signed in, is somebody pressing reload on the consent screen, and aborting their
     * authorization for it would be a bug wearing a security control's clothes. A ticket
     * naming somebody else is the wrong-principal case and stays refused.
     *
     * The row survives until {@see LoginTicket::prunable()} sweeps it an hour later, which
     * is what makes this answerable at all.
     */
    public function subjectOf(string $token): ?string
    {
        $ticket = LoginTicket::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $ticket instanceof LoginTicket ? $ticket->subject_id : null;
    }
}
