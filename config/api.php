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
        // The operator API (`/api/v1/platform`) and a person's own account (`/api/v1/me`):
        // a person's delegated token, so a person's pace.
        'platform' => (int) env('CBOX_ID_API_RATE_LIMIT_PLATFORM', 60),
        'account' => (int) env('CBOX_ID_API_RATE_LIMIT_ACCOUNT', 60),
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

        /*
         * Whether the PLATFORM ROOT of a multi-tenant deployment signs MCP clients in for
         * its own `/mcp` — `claude mcp add --transport http cbox-id https://<root>/mcp`, one
         * connection for a person's whole workspace. On by default.
         *
         * The root is not an identity provider for anybody's app, and this does not make it
         * one: it serves exactly what an MCP client needs and nothing an app would — the
         * RFC 8414 document (written for the root, no OpenID Connect in it), registration
         * in the `mcp` profile, client ID metadata documents, `/oauth/authorize` and the
         * token endpoints for such a client, and every token it issues audienced to the
         * root's `/mcp` alone. Discovery, `openid`, ID tokens, UserInfo, SAML and SCIM stay
         * absent there, and only a workspace's team or an operator can finish the sign-in.
         * See App\Platform\OAuth\RootMcpOAuth and docs/security/_index.md.
         *
         * Needs `dynamic_clients` above as well: with self-registered clients closed out of
         * `/mcp` there is nothing at the root for one to be signed in to. Off, the root is
         * back to what it was — its own first-party clients only (the `cbox` CLI) — and
         * every surface listed above answers 404 there.
         */
        'root_oauth' => (bool) env('CBOX_ID_ROOT_MCP_OAUTH', true),
    ],

];
