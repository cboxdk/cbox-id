<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Which peers' `X-Forwarded-*` headers this deployment believes: `*` (whoever connects —
| right only where nothing but the ingress can reach the app, as on Kubernetes behind a
| Cloudflare Tunnel) or a comma-separated list of addresses and CIDRs (production names the
| pod network, `10.244.0.0/16`). Unset trusts NOBODY, on purpose: an app reachable directly
| must not let a caller forge its client IP, which the audit trail records and every per-IP
| rate limit keys on.
|
| A CONFIG FILE, NOT AN `env()` CALL IN bootstrap/app.php, because that is where it used to
| be read — and `withMiddleware()` runs when the HTTP kernel is resolved, before the
| application has bootstrapped. Two consequences, both silent:
|
|  - after `php artisan config:cache` (which docs/operations/deployment.md tells every VM
|    deployment to run, and which the production image runs at every container start
|    under LARAVEL_AUTO_OPTIMIZE) `.env` is never loaded, so a TRUSTED_PROXIES written in
|    `.env` read as empty and no proxy was trusted;
|  - with no proxy trusted, Laravel builds every URL from the request as the app saw it —
|    `http`, because TLS ended one hop earlier — so redirects, mailed links and
|    absolute URLs went out as http, and every client shared the proxy's IP.
|
| Production on Kubernetes never noticed: there the variable is in the process environment,
| which `env()` reads with or without a cache. A VM with a `.env` and a config cache — the
| documented self-hosting path — got http everywhere. Laravel's TrustProxies middleware
| reads this key itself (`trustedproxy.proxies`) whenever nothing was pinned with `at:`,
| and a config file is read after `.env` and is what `config:cache` freezes.
|
| The empty case is `[]`, not null: null lets Laravel trust everybody on Laravel Cloud,
| Forge and Vapor hosts, and this application trusts nobody unless told to.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match ($proxies) {
        '' => [],
        '*' => '*',
        default => array_values(array_filter(array_map(trim(...), explode(',', $proxies)), static fn (string $proxy): bool => $proxy !== '')),
    },
];
