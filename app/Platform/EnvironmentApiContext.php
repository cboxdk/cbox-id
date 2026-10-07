<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateMcp;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * The credential authenticated for the current request on ONE environment's management
 * plane: an environment API key — the machine equivalent of an admin's session — or a
 * person's own access token, signed in through an agent or the CLI. The environment itself
 * is already resolved from the request host (ResolveEnvironment), so this only carries the
 * credential. Bound per-request (scoped) and populated by {@see AuthenticateEnvironmentApi}
 * and {@see AuthenticateMcp}.
 *
 * At most one of the two is set. {@see key()} stays the KEY alone, because the routes that
 * read it — the ones not yet actions — act as a key and nothing else; {@see Principal()} is
 * the question the action layer asks.
 */
final class EnvironmentApiContext
{
    private ?EnvironmentApiKey $key = null;

    private ?DelegatedTokenPrincipal $delegated = null;

    public function set(EnvironmentApiKey $key): void
    {
        $this->key = $key;
        $this->delegated = null;
    }

    public function setDelegated(DelegatedTokenPrincipal $principal): void
    {
        $this->delegated = $principal;
        $this->key = null;
    }

    /**
     * Forget the key once its request is answered.
     *
     * A `scoped` binding is only reset between requests under Octane. Everywhere else — a
     * queue worker, the test suite, a console command running after an in-process request —
     * the key would stay "authenticated" for whatever ran next, and
     * {@see EnvironmentKeyAuditLog} would attribute that work to it. The same for a person's
     * token.
     */
    public function clear(): void
    {
        $this->key = null;
        $this->delegated = null;
    }

    public function key(): ?EnvironmentApiKey
    {
        return $this->key;
    }

    /** The person whose access token authenticated this request, when one did. */
    public function delegated(): ?DelegatedTokenPrincipal
    {
        return $this->delegated;
    }

    /** Whoever this request acts as, in the action layer's terms. */
    public function principal(): ?Principal
    {
        if ($this->key !== null) {
            return new EnvironmentKeyPrincipal($this->key);
        }

        return $this->delegated;
    }

    /** The environment the authenticated credential belongs to (host-resolved and credential-bound agree). */
    public function environmentId(): ?string
    {
        return $this->key->environment_id ?? $this->delegated?->environmentId();
    }
}
