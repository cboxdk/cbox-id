<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\AccountLinking\AccountLinking;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\FederatedLanding;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\Exceptions\AccountInactive;
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
        private readonly FederatedLanding $landing,
        private readonly AccountLinking $linking,
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
            return $this->landing->failed(FederationFailure::Inactive, ['provider' => $model === null ? '' : $model->name]);
        }

        $samlResponse = $request->input('SAMLResponse');

        if (! is_string($samlResponse) || $samlResponse === '') {
            return $this->landing->failed(FederationFailure::Incomplete, ['provider' => $model->name]);
        }

        try {
            $principal = $this->validator->validate($model, $samlResponse);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            Log::warning('cbox-id: SAML assertion rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->landing->failed(FederationFailure::Rejected, ['provider' => $model->name]);
        }

        try {
            $session = $this->flow->completeLogin($model, $principal);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            // Log the real reason; show the user something they can act on. The detail
            // is an assertion-forgery oracle if returned.
            Log::warning('cbox-id: SAML assertion rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->landing->failed(FederationFailure::Rejected, ['provider' => $model->name]);
        } catch (AccountInactive) {
            return $this->landing->failed(FederationFailure::AccountDisabled, ['provider' => $model->name]);
        } catch (AccountExistsForEmail) {
            // An address the organization has proven, already somebody's here: the same
            // link step as a social sign-in. An assertion whose address is outside the
            // organization's verified domains is not vouched for, and is told so.
            return $this->linking->begin($request, $principal, $model, null, $model->name, 'sso');
        }

        // THE missing inch. The landing turns an already-started framework session into
        // the browser cookie (rotating the session id against fixation), and picks the
        // plane: a tenant host signs the subject in; the account host signs an account
        // MEMBER in, and refuses a subject that is not one.
        return $this->landing->land($request, $session);
    }
}
