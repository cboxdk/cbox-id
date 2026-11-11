<?php

declare(strict_types=1);

namespace App\Platform\Api;

/**
 * Every URL the application hands out for its management API, from one place.
 *
 * `https://{api host}/v1/…`. Without an API host (a deployment that never set one) the
 * request's own host carries `/v1`, so a link is never built from a hard-coded `/api/v1`.
 */
final class ApiUrls
{
    public static function origin(): string
    {
        $host = ApiHost::name();

        return $host !== null ? 'https://'.$host : rtrim(request()->getSchemeAndHttpHost(), '/');
    }

    /** `https://api.cboxid.com/v1` — what a client saves as its base. */
    public static function base(): string
    {
        return self::origin().'/v1';
    }

    /** One path under the base: `to('/users')` → `https://api.cboxid.com/v1/users`. */
    public static function to(string $path = ''): string
    {
        $path = trim($path, '/');

        return $path === '' ? self::base() : self::base().'/'.$path;
    }

    /** The one OpenAPI document for every plane. */
    public static function openApi(): string
    {
        return self::origin().'/openapi.json';
    }

    /** The rendered reference. */
    public static function docs(): string
    {
        return self::origin().'/docs';
    }

    /** The RFC 9457 `type` of a problem with this machine code. */
    public static function problem(string $error): string
    {
        return self::origin().'/problems/'.rawurlencode($error);
    }
}
