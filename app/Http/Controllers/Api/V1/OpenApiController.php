<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Platform\Api\V1\V1OpenApi;
use Illuminate\Http\Response;

/**
 * `GET /openapi.json`: the one contract for every plane, public so tooling and generated
 * clients can read it without a key. Built by `php artisan openapi:build` and committed.
 */
final class OpenApiController
{
    public function __invoke(): Response
    {
        $document = @file_get_contents(V1OpenApi::path());
        abort_if($document === false, 404);

        return response($document, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
