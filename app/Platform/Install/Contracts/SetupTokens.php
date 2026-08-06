<?php

declare(strict_types=1);

namespace App\Platform\Install\Contracts;

/**
 * The secret that gates the first-run screen.
 *
 * WHY A TOKEN AT ALL. The obvious lazy bootstrap — "if the platform is empty, let the
 * first visitor claim it" — hands the deployment to whoever reaches it first, and on a
 * public identity provider that is a port scanner, not the operator. The emptiness check
 * alone is a race the operator does not know they are in.
 *
 * So possession of the token stands in for the thing that actually distinguishes the
 * operator from a stranger: access to the machine. It is written where only filesystem
 * or console access can read it, never rendered into the page and never carried in a URL
 * (query strings reach logs, proxies and `Referer` headers), and it stops existing the
 * moment the platform is claimed.
 */
interface SetupTokens
{
    /**
     * The current token, minting and recording one if this empty platform has not been
     * armed yet. Idempotent: an armed platform keeps the token it already published, so
     * a second visitor does not invalidate the value the operator is holding.
     */
    public function issue(): string;

    /** The current token, or null when none has been issued (or it has been spent). */
    public function current(): ?string;

    /** Constant-time comparison against the issued token. False when none exists. */
    public function matches(string $candidate): bool;

    /** Spend the token — called once the platform has been claimed, never before. */
    public function forget(): void;
}
