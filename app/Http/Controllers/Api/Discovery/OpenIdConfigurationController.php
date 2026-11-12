<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Discovery;

use App\Platform\OAuth\SupportedPrompts;
use Cbox\Id\Api\Http\Controllers\DiscoveryController;
use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

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
    public function __construct(
        private readonly SupportedPrompts $prompts,
        private readonly EnvironmentContext $environments,
    ) {}

    /** How long a built document is kept, in seconds. */
    public const KEPT_FOR = 60;

    /**
     * KEPT FOR A MINUTE. Building the document reads the public scopes, the platform root
     * and the sign-up setting and took 18 ms of a 73 ms request on production (2026-11-12),
     * and every app reads it when it starts a sign-in. It is kept per environment, issuer,
     * host and release, so a change of issuer (a verified custom domain) is a new document
     * at once and a deploy never serves the previous release's. A new public scope or a
     * changed sign-up setting shows within the minute; nothing is authorized from this
     * document, the endpoints it names check for themselves.
     */
    public function __invoke(): JsonResponse
    {
        $version = config('app.version');
        $key = 'cbox-id:discovery:openid:'.hash('sha256', implode('|', [
            $this->environments->current()?->environmentKey() ?? '',
            ServerMetadata::issuer(),
            mb_strtolower(request()->getHost()),
            is_string($version) ? $version : '',
        ]));

        return response()->json(Cache::remember(
            $key,
            self::KEPT_FOR,
            fn (): array => $this->prompts->describe(ServerMetadata::document()),
        ));
    }
}
