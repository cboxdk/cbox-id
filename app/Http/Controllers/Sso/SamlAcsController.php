<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The browser-facing SAML Assertion Consumer Service.
 *
 * The framework's own ACS validates the assertion and returns the resulting session as
 * JSON, on the explicit understanding that a hosting app turns it into a cookie. This
 * app never did — so an enterprise user authenticated at their IdP and landed on a raw
 * JSON blob, never signed in. The protocol layer was sound; the last inch was missing.
 *
 * Unauthenticated by design: the assertion's XML signature IS the authentication, and it
 * is verified before any identity is read.
 */
final class SamlAcsController extends Controller
{
    public function __construct(
        private readonly Connections $connections,
        private readonly AssertionValidator $validator,
        private readonly FederationFlow $flow,
        private readonly PlatformAuth $auth,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        // The type check is not cosmetic: the validator dispatches on $connection->type,
        // so POSTing an id_token here as "SAMLResponse" against an OIDC connection id
        // would route to the OIDC validator — which has no nonce and no replay guard,
        // because those live in the OIDC controller's state/nonce stash. Without this,
        // the CSRF-exempt SAML ACS is a replay endpoint for OIDC connections.
        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::Saml) {
            return $this->failed('That single sign-on connection is no longer active. Ask your IT administrator to re-enable it.');
        }

        $samlResponse = $request->input('SAMLResponse');

        if (! is_string($samlResponse) || $samlResponse === '') {
            return $this->failed('That sign-in response was incomplete. Start again from your identity provider.');
        }

        try {
            $principal = $this->validator->validate($model, $samlResponse);
            $session = $this->flow->completeLogin($model, $principal);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            // Log the real reason; show the user something they can act on. The detail
            // is an assertion-forgery oracle if returned.
            Log::warning('cbox-id: SAML assertion rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->failed('We could not verify that sign-in. Please try again, or contact your IT administrator.');
        } catch (AccountExistsForEmail) {
            return $this->failed(
                'An account already exists for that email address. Sign in with your password first, then connect single sign-on from your account settings.'
            );
        }

        // THE missing inch. adopt() is the same seam magic-link redemption uses: it
        // turns an already-started framework session into the browser cookie, rotating
        // the session id against fixation.
        $this->auth->adopt($request, $session);

        return redirect()->intended(route('dashboard'));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['identifier' => $message]);
    }
}
