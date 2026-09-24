<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Discovery;

use App\Platform\OAuth\SupportedPrompts;
use Cbox\Id\Api\Http\Controllers\DiscoveryController;
use Cbox\Id\Api\Support\ServerMetadata;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/openid-configuration`, as the framework serves it plus what this
 * application's `/authorize` does with `prompt`.
 *
 * Bound over the framework's controller in the container rather than re-registered as a
 * route: the framework's route keeps its middleware (environment resolution, the canonical
 * issuer host, the throttle), and a re-registration here would have to repeat all of it —
 * which is exactly how the SAML overrides once lost theirs.
 */
final class OpenIdConfigurationController extends DiscoveryController
{
    public function __construct(private readonly SupportedPrompts $prompts) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->prompts->describe(ServerMetadata::document()));
    }
}
