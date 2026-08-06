<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\OAuth2Client;
use Cbox\Id\Federation\ProviderCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `GET /sso/oauth2/{connection}/redirect` — begins a sign-in against a provider that
 * speaks OAuth 2.0 and nothing more (GitHub, Discord, Facebook).
 *
 * The OIDC pair of these lives in the framework and returns JSON; the browser-facing
 * halves live here, because turning a completed federation into a session cookie is the
 * host application's job and the library has no opinion about how this one does it.
 *
 * There is no nonce, because there is no `id_token` to bind one to. `state` is therefore
 * carrying the whole weight of CSRF on the callback: without it, that endpoint is an
 * unauthenticated URL that signs somebody in.
 */
final class OAuth2RedirectController extends Controller
{
    public function __construct(
        private readonly Connections $connections,
        private readonly OAuth2Client $client,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        // The environment scope on Connection is what stops one tenant's id resolving on
        // another tenant's host; this adds the status and protocol checks. An inactive
        // connection must not start a flow it cannot finish.
        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::OAuth2) {
            abort(404);
        }

        $template = ProviderCatalog::find((string) $model->provider);

        if ($template === null) {
            abort(404);
        }

        try {
            $config = $this->connections->oauth2Config($model);
        } catch (InvalidAssertion) {
            // Half-configured, or stored against a provider that speaks OIDC. Either way
            // this flow cannot complete, and sending the browser to a provider now would
            // fail at the callback where the person can do nothing about it.
            abort(404);
        }

        $state = bin2hex(random_bytes(16));
        $request->session()->put('oauth2.'.$model->id, ['state' => $state]);

        return new RedirectResponse($this->client->authorizeUrl(
            $template,
            $config,
            url('/sso/oauth2/'.$model->id.'/callback'),
            $state,
        ));
    }
}
