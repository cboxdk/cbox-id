<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * The environment API key authenticated for the current request — the machine
 * equivalent of an admin's session on ONE environment's management plane. The
 * environment itself is already resolved from the request host (ResolveEnvironment),
 * so this only carries the key (and thus its scopes). Bound per-request (scoped)
 * and populated by {@see AuthenticateEnvironmentApi}.
 */
final class EnvironmentApiContext
{
    private ?EnvironmentApiKey $key = null;

    public function set(EnvironmentApiKey $key): void
    {
        $this->key = $key;
    }

    /**
     * Forget the key once its request is answered.
     *
     * A `scoped` binding is only reset between requests under Octane. Everywhere else — a
     * queue worker, the test suite, a console command running after an in-process request —
     * the key would stay "authenticated" for whatever ran next, and
     * {@see EnvironmentKeyAuditLog} would attribute that work to it.
     */
    public function clear(): void
    {
        $this->key = null;
    }

    public function key(): ?EnvironmentApiKey
    {
        return $this->key;
    }

    /** The environment the authenticated key belongs to (host-resolved and key-bound agree). */
    public function environmentId(): ?string
    {
        return $this->key?->environment_id;
    }
}
