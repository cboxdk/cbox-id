<?php

declare(strict_types=1);

return [

    /*
     * Where uploaded brand assets (logo, favicon) are kept.
     *
     * `store` is `database` by default: the image is a row every replica shares and the
     * application serves it at `/brand-assets/…`. That is the only default that works on
     * more than one web replica — production runs two, and replaces both on every deploy —
     * where a file on one pod's disk is missing on the other and lost on the next rollout.
     *
     * `disk` writes to the Laravel filesystem disk named below instead (the `public` disk
     * needs `php artisan storage:link` to be served at all), for a single machine; bind
     * ObjectStorageBrandAssetStore for shared object storage. Assets are namespaced per
     * environment under `path` either way.
     */
    'assets' => [
        'store' => env('WHITELABEL_ASSETS_STORE', 'database'),
        'disk' => env('WHITELABEL_ASSETS_DISK', 'public'),
        'path' => 'brand',
        'cdn_base_url' => env('WHITELABEL_ASSETS_CDN_URL'),
    ],

    /*
     * Custom brand domains. When `verify_host` is true, a domain is run through
     * laravel-ssrf's guard before it is stored, refusing IP literals and
     * private/reserved/blocked hosts (so a tenant can't point the platform at an
     * internal name). Disable ONLY on a single-tenant/on-prem install.
     */
    'custom_domain' => [
        'verify_host' => env('WHITELABEL_VERIFY_DOMAIN', true),
    ],

];
