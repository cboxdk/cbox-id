<?php

declare(strict_types=1);

return [

    /*
     * Where uploaded brand assets (logo, favicon) are written. `disk` is a
     * Laravel filesystem disk; the default `public` disk keeps the framework free
     * of any hard S3 dependency. Point it at an `s3` disk (and bind the
     * ObjectStorageBrandAssetStore) for a horizontally-scaled deployment. Assets
     * are namespaced per environment under `path`.
     */
    'assets' => [
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
