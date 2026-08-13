<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\FederatedLanding;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\OidcClient;
use Cbox\Id\Federation\Support\FederationFlowStash;
use Cbox\Id\Federation\Support\FirstAuthorizationProfile;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The browser-facing OIDC federation callback — the OIDC half of the same gap the SAML
 * ACS had: the framework validated the id_token and returned the session as JSON, and
 * nothing turned it into a cookie, so a user who authenticated at their IdP was never
 * actually signed in.
 *
 * State and nonce are checked exactly as the framework's own callback does; both are
 * single-use because the stash is pulled, not read.
 */
final class OidcCallbackController extends Controller
{
    public function __construct(
        private readonly Connections $connections,
        // Concrete: the framework ships no contract for the OIDC RP, and its own
        // callback injects this class directly. Matching that rather than inventing a
        // local interface the framework would not bind.
        private readonly OidcClient $client,
        private readonly AssertionValidator $validator,
        private readonly FederationFlow $flow,
        private readonly FederatedLanding $landing,
        private readonly FederationFlowStash $stash,
        private readonly FirstAuthorizationProfile $firstAuthorization,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::Oidc) {
            return $this->failed('That single sign-on connection is no longer active. Ask your IT administrator to re-enable it.');
        }

        // Pulled, not read: replaying a callback finds nothing stashed and fails closed.
        //
        // THROUGH THE STASH, not `session()->pull()` directly, because the redirect leg
        // that wrote these is the FRAMEWORK's and it writes both a session entry and a
        // `SameSite=None` cookie. A `form_post` answer is a cross-site POST, which a
        // `SameSite=Lax` session cookie is not sent with — so this controller reading the
        // session alone found nothing on exactly the callbacks that need it most, and
        // told the person their sign-in link had expired.
        $expected = $this->stash->pull($request, $model->id);

        // From the query OR the body, which is what lets one handler serve both bindings.
        $state = $request->string('state')->toString();
        $code = $request->string('code')->toString();

        // CSRF: the state must be the one we issued for THIS browser. A stale state is
        // routine (a bookmarked callback, the back button), so send the user back to sign
        // in rather than showing them an error page.
        if ($expected === null || $code === '' || ! $expected->matches($state)) {
            return $this->failed('That sign-in link has expired. Please sign in again.');
        }

        try {
            $idToken = $this->client->exchangeCode($model, $code, url('/sso/oidc/'.$model->id.'/callback'));
            $principal = $this->validator->validate($model, $idToken);

            $nonce = $principal->raw['nonce'] ?? null;

            if (! is_string($nonce) || ! hash_equals($expected->nonce, $nonce)) {
                Log::warning('cbox-id: OIDC nonce mismatch.', ['connection_id' => $model->id]);

                return $this->failed('We could not verify that sign-in. Please try again.');
            }

            // THE NAME A PROVIDER SENDS ONCE, outside the assertion — Apple, and only
            // Apple, on the first authorization. This route SHADOWS the framework's, so
            // the merge the framework does was dead on this deployment: every Sign in
            // with Apple account was still created with a null name, permanently.
            $principal = $this->firstAuthorization->merge($model, $request, $principal);

            $session = $this->flow->completeLogin($model, $principal);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            Log::warning('cbox-id: OIDC login rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->failed('We could not verify that sign-in. Please try again, or contact your IT administrator.');
        } catch (AccountExistsForEmail) {
            return $this->failed(
                'An account already exists for that email address. Sign in with your password first, then connect single sign-on from your account settings.'
            );
        }

        // Plane-aware landing: a tenant host signs the subject in; the account host
        // signs an account MEMBER in, and refuses a subject that is not one.
        return $this->landing->land($request, $session);
    }

    /**
     * Delegated to the landing so the ERROR branch forks on the plane exactly as the
     * SUCCESS branch does. This route is not plane-gated, so on the account host a
     * hard-coded `route('login')` here was a 404.
     */
    private function failed(string $message): RedirectResponse
    {
        return $this->landing->failed($message);
    }
}
