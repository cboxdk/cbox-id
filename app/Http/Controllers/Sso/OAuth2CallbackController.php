<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\FederatedLanding;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\OAuth2Client;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `GET /sso/oauth2/{connection}/callback` — completes a sign-in against a provider that
 * speaks OAuth 2.0 and nothing more.
 *
 * Worth being precise about what is and is not proven here, because this looks like the
 * OIDC callback and assures less. There is no `id_token`, so nothing is signature-checked
 * and there is no nonce to bind. What holds it up instead:
 *
 *  - `state`, pulled rather than read, so a replayed callback finds nothing and fails
 *    closed. Without it this URL is unauthenticated and signs somebody in.
 *  - The code was exchanged at the provider's own token endpoint using our client
 *    secret, over TLS — so the token was issued to US.
 *  - The profile came from the endpoint the CATALOGUE names, not one an administrator
 *    typed. Otherwise a connection labelled "GitHub" could point at any server.
 *
 * That is enough to say this browser controls that provider account. It says nothing
 * about the email address attached to it, which is why the principal goes through the
 * same federation flow as everything else — the one that refuses to merge into an
 * existing account by email.
 */
final class OAuth2CallbackController extends Controller
{
    public function __construct(
        private readonly Connections $connections,
        private readonly OAuth2Client $client,
        private readonly FederationFlow $flow,
        private readonly FederatedLanding $landing,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::OAuth2) {
            return $this->failed('That sign-in method is no longer available. Please sign in another way.');
        }

        $template = ProviderCatalog::find((string) $model->provider);

        if ($template === null) {
            return $this->failed('That sign-in method is no longer available. Please sign in another way.');
        }

        // Pulled, not read: replaying a callback finds nothing stashed.
        $stashed = $request->session()->pull('oauth2.'.$model->id);
        $expectedState = is_array($stashed) && is_string($stashed['state'] ?? null) ? $stashed['state'] : null;

        $state = $request->string('state')->toString();
        $code = $request->string('code')->toString();

        // A stale state is routine — a bookmarked callback, the back button, a second
        // tab — so this sends people back to sign in rather than showing an error page.
        if ($expectedState === null || $code === '' || ! hash_equals($expectedState, $state)) {
            return $this->failed('That sign-in link has expired. Please sign in again.');
        }

        try {
            $principal = $this->client->principal(
                $template,
                $this->connections->oauth2Config($model),
                $code,
                url('/sso/oauth2/'.$model->id.'/callback'),
                $model->id,
            );

            $session = $this->flow->completeLogin($model, $principal);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            Log::warning('cbox-id: OAuth 2.0 login rejected.', [
                'connection_id' => $model->id,
                'provider' => $model->provider,
                'reason' => $e->getMessage(),
            ]);

            return $this->failed('We could not complete that sign-in. Please try again.');
        } catch (AccountExistsForEmail) {
            return $this->failed(
                'An account already exists for that email address. Sign in with your password first, then connect this provider from your account settings.'
            );
        }

        return $this->landing->land($request, $session);
    }

    private function failed(string $message): RedirectResponse
    {
        return $this->landing->failed($message);
    }
}
