<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateDelegatedApi;
use App\Platform\Actions\Principal\PersonPrincipal;

/**
 * The person a delegated token authenticated for this request, on the platform or the
 * account plane. Bound per request (scoped) and filled by {@see AuthenticateDelegatedApi}
 * — the counterpart of {@see WorkspaceApiContext} for the planes no key reaches.
 */
final class DelegatedApiContext
{
    private ?PersonPrincipal $principal = null;

    public function set(PersonPrincipal $principal): void
    {
        $this->principal = $principal;
    }

    public function clear(): void
    {
        $this->principal = null;
    }

    public function principal(): ?PersonPrincipal
    {
        return $this->principal;
    }
}
