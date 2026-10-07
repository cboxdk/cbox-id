<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Http\Middleware\AuthenticateMcp;
use App\Platform\Actions\Principal\Principal;
use App\Platform\EnvironmentApiContext;

/**
 * Who is calling the MCP server on this request, as a {@see Principal}.
 *
 * Set by {@see AuthenticateMcp} and read by every tool. A principal rather than the
 * management key itself, because the key is only the first credential `/mcp` accepts: a
 * delegated OAuth access token (an agent acting for a person) becomes a different
 * principal with different rules, and the tools must not need to change when it arrives —
 * they ask the principal what it may do, exactly as the action runner does.
 *
 * Bound `scoped` and cleared when the request is answered, for the same reason as
 * {@see EnvironmentApiContext}: a scoped binding is only reset between requests under
 * Octane, and a principal left behind would authenticate whatever ran next.
 */
final class McpCaller
{
    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function clear(): void
    {
        $this->principal = null;
    }

    public function principal(): ?Principal
    {
        return $this->principal;
    }
}
