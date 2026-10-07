<?php

declare(strict_types=1);

return [

    /*
     * Per-minute request budgets for the REST management API, one per plane.
     *
     * These are CREDENTIAL budgets, not IP budgets: the bucket is keyed on the API
     * key (see App\Http\ApiRateLimiters), so a customer whose CI egresses through a
     * shared NAT — which is every hosted CI runner — gets its own allowance instead
     * of sharing one with every other tenant behind the same address. A Terraform
     * plan/apply or an SDK sync is exactly the burst that used to collide.
     */
    'rate_limits' => [
        'workspace' => (int) env('CBOX_ID_API_RATE_LIMIT_WORKSPACE', 120),
        'environment' => (int) env('CBOX_ID_API_RATE_LIMIT_ENVIRONMENT', 240),
        'vault' => (int) env('CBOX_ID_API_RATE_LIMIT_VAULT', 120),
        'apps' => (int) env('CBOX_ID_API_RATE_LIMIT_APPS', 60),
        // The MCP server at `/mcp`. Its own bucket rather than the environment plane's:
        // an agent's session (list, read, act, read again) is chattier than a sync job, and
        // one should not spend the other's allowance on the same key.
        'mcp' => (int) env('CBOX_ID_API_RATE_LIMIT_MCP', 240),
    ],

    /*
     * The abuse backstop, as a multiple of the plane's per-credential budget.
     *
     * A per-credential bucket alone is escapable: the credential is only known to be
     * VALID after authentication, so a flood of distinct bogus tokens would otherwise
     * get a fresh bucket per token. This second, per-IP limit closes that — set high
     * enough (default 10x) that it never binds on legitimate multi-tenant traffic
     * from one egress address, but still bounds a single source.
     *
     * Raise it for a deployment that genuinely fronts more than ~10 busy tenants
     * behind one address; set it to 0 to disable the backstop entirely.
     */
    'ip_ceiling_multiplier' => (int) env('CBOX_ID_API_RATE_LIMIT_IP_MULTIPLIER', 10),

    /*
     * The MCP server (`/mcp` on each environment host).
     *
     * `tool_search` groups the action tools behind laravel/mcp's `search_tools` /
     * `execute_tools` instead of listing each one. Off by default: behind `execute_tools`
     * a client can no longer see which call is destructive and ask a person first, which
     * is worth more than the context it saves while the catalogue is small and clients
     * defer MCP tools themselves. See App\Mcp\IdServer.
     */
    'mcp' => [
        'tool_search' => (bool) env('CBOX_ID_MCP_TOOL_SEARCH', false),

        /*
         * Whether an MCP client that registered ITSELF — RFC 7591 in `mcp` mode, or a
         * client ID metadata document — may be issued a token for `/mcp`. On by default:
         * that is how Claude Code and every other MCP client signs a person in, knowing
         * nothing but the server's URL. The person is still asked on the consent screen,
         * every time, and the token is still bounded by what they may do themselves.
         *
         * Off, `/mcp` takes tokens only for clients an administrator registered (the
         * `cbox` CLI among them) and management keys. See App\Mcp\McpProtectedResources.
         */
        'dynamic_clients' => (bool) env('CBOX_ID_MCP_DYNAMIC_CLIENTS', true),
    ],

];
