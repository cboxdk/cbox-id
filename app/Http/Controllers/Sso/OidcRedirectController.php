<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\FederatedLanding;
use Cbox\Id\Api\Http\Controllers\Sso\OidcRedirectController as FrameworkOidcRedirect;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /sso/oidc/{connection}/redirect` — the "Continue with Google" button, as a person
 * reaches it.
 *
 * The framework's controller does the work: it mints the state and nonce, stashes them for
 * the callback and builds the authorization URL. What it answers when it CANNOT is JSON — a
 * 404 for a connection that is switched off, a 502 for one whose provider cannot be reached —
 * which is right for an API and wrong for a button: the person who clicked it got a page of
 * raw JSON, or a bare error, instead of the sign-in page and a sentence. So this shadows the
 * route (the app's routes are registered first), asks the framework for the redirect, and
 * turns anything that is not one into the same visible, translated failure every other
 * federated door lands on ({@see FederatedLanding::failed()}).
 */
final class OidcRedirectController extends Controller
{
    public function __construct(
        private readonly Connections $connections,
        private readonly FrameworkOidcRedirect $redirect,
        private readonly FederatedLanding $landing,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::Oidc) {
            return $this->landing->failed(FederationFailure::Inactive, ['provider' => $model === null ? '' : $model->name]);
        }

        try {
            $response = ($this->redirect)($request, $connection);
        } catch (ConnectionException $e) {
            Log::warning('cbox-id: OIDC discovery unreachable.', ['connection_id' => $model->id, 'reason' => $e->getMessage()]);

            return $this->landing->failed(FederationFailure::Unavailable, ['provider' => $model->name]);
        } catch (Throwable $e) {
            // A provider whose discovery document is malformed, an SSRF refusal: nothing the
            // person can fix, logged for whoever can.
            report($e);

            return $this->landing->failed(FederationFailure::Unavailable, ['provider' => $model->name]);
        }

        if ($response instanceof RedirectResponse) {
            return $response;
        }

        return $this->landing->failed(FederationFailure::Unavailable, ['provider' => $model->name]);
    }
}
