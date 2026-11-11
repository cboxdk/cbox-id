<?php

declare(strict_types=1);

namespace App\Http;

use App\Platform\Api\ApiHost;
use Illuminate\Http\Request;

/**
 * Whether a request is a machine call to the API — answered in JSON, never with a page or a
 * redirect. One answer for every place that asks, so a new API path cannot be JSON in one
 * of them and HTML in another.
 */
final class ApiRequest
{
    public static function is(Request $request): bool
    {
        return ApiHost::serves($request) || $request->is('api/*', 'mcp', 'internal/*');
    }

    /**
     * Whether its failures are RFC 9457 problems: `/v1` and everything else on the API host,
     * except an old path reached there in transition, which keeps the shape it always had.
     */
    public static function speaksProblems(Request $request): bool
    {
        return ApiHost::serves($request) && ! $request->is('api/*');
    }
}
