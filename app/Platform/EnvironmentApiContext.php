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
