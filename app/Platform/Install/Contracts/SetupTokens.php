<?php

declare(strict_types=1);

namespace App\Platform\Install\Contracts;

use Carbon\CarbonImmutable;

/**
 * The secret that gates the first-run screen.
 *
 * WHY A TOKEN AT ALL. The obvious lazy bootstrap — "if the platform is empty, let the
 * first visitor claim it" — hands the deployment to whoever reaches it first, and on a
 * public identity provider that is a port scanner, not the operator. The emptiness check
 * alone is a race the operator does not know they are in.
 *
 * So possession of the token stands in for the thing that actually distinguishes the
 * operator from a stranger: access to the deployment. It is handed out where only console
 * access can read it, never rendered into the page and never carried in a URL
 * (query strings reach logs, proxies and `Referer` headers), and it stops existing the
 * moment the platform is claimed.
 *
 * SHARED BY EVERY REPLICA. A deployment with more than one web instance must accept a
 * token minted on one of them at any of the others — the look at `/first-run` and the
 * submit are two requests, and the load balancer owes them no affinity — so the token is
 * kept in a store they all read, never on one instance's disk.
 *
 * NEVER READ BACK. Only a hash is kept, so the value exists in exactly the places it was
 * handed out at mint time: the output of `cbox-id:setup-token`, and — only where the
 * deployment opted in — the log line written when an empty platform is first looked at.
 */
interface SetupTokens
{
    /**
     * Arm this empty platform: mint and announce a token unless a live one exists.
     *
     * Idempotent, and safe to race: an armed platform keeps the token it already handed
     * out, so a second visitor — or a second replica — does not invalidate the value the
     * operator is holding. An expired token counts as none, so the next look re-arms.
     */
    public function arm(): void;

    /**
     * Mint a fresh token, replacing any live one, and return it.
     *
     * The only way to get a token's value after the fact, because none is stored: an
     * operator who needs it mints a new one, and the old one stops working at once.
     */
    public function rotate(): string;

    /** Whether a live token exists — issued, not yet spent, not yet expired. */
    public function armed(): bool;

    /** When the live token stops working, or null when there is none. */
    public function expiresAt(): ?CarbonImmutable;

    /** Constant-time comparison against the live token. False when none exists. */
    public function matches(string $candidate): bool;

    /** Spend the token — called once the platform has been claimed, never before. */
    public function forget(): void;
}
