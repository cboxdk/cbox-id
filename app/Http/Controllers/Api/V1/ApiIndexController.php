<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Platform\Api\ApiUrls;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1`: the base a client saves, answering for itself. It names the contract and the
 * reference, so a person who opens the base URL the console showed them is never left at a
 * 404 with nowhere to go.
 */
final class ApiIndexController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'object' => 'api',
            'version' => 'v1',
            'base_url' => ApiUrls::base(),
            'openapi_url' => ApiUrls::openApi(),
            'documentation_url' => ApiUrls::docs(),
            'resource_metadata' => ApiUrls::origin().'/.well-known/oauth-protected-resource',
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
