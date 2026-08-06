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
        'organization' => (int) env('CBOX_ID_API_RATE_LIMIT_ORGANIZATION', 120),
        'environment' => (int) env('CBOX_ID_API_RATE_LIMIT_ENVIRONMENT', 240),
        'vault' => (int) env('CBOX_ID_API_RATE_LIMIT_VAULT', 120),
        'apps' => (int) env('CBOX_ID_API_RATE_LIMIT_APPS', 60),
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

];
