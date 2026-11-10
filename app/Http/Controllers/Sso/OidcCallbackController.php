<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Platform\AccountLinking\AccountLinking;
use App\Platform\AccountLinking\ConnectFromAccount;
use App\Platform\AccountLinking\ConnectIntent;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\FederatedLanding;
use App\Platform\SignInThrough;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\FederationFlow;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\ConnectionInactive;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\OidcClient;
use Cbox\Id\Federation\Support\FederationFlowStash;
use Cbox\Id\Federation\Support\FirstAuthorizationProfile;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\Exceptions\AccountInactive;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\OAuthServer\Enums\AuthMethod;
use Illuminate\Http\Client\ConnectionException;
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
        private readonly AccountLinking $linking,
        private readonly ConnectFromAccount $connect,
        private readonly SignInThrough $through,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::Oidc) {
            return $this->landing->failed(FederationFailure::Inactive, ['provider' => $model === null ? '' : $model->name]);
        }

        $label = ['provider' => $model->name];

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

        // The provider answered with an ERROR instead of a code — the person pressed
        // Cancel on its consent screen, or it refused the request. Said as that, not as an
        // expired link: "try again" is right, "your link expired" is a lie they will
        // believe and act on. Only once the state matches, so a forged error cannot put
        // words on somebody's screen.
        if ($expected !== null && $code === '' && $request->filled('error') && $expected->matches($state)) {
            Log::info('cbox-id: OIDC provider returned an error.', [
                'connection_id' => $model->id,
                'error' => $request->string('error')->limit(100)->toString(),
            ]);

            return $this->landing->failed(FederationFailure::Cancelled, $label);
        }

        // CSRF: the state must be the one we issued for THIS browser. A stale state is
        // routine (a bookmarked callback, the back button), so send the user back to sign
        // in rather than showing them an error page.
        if ($expected === null || $code === '' || ! $expected->matches($state)) {
            return $this->landing->failed(FederationFailure::Expired, $label);
        }

        try {
            $idToken = $this->client->exchangeCode($model, $code, url('/sso/oidc/'.$model->id.'/callback'));
            $principal = $this->validator->validate($model, $idToken);

            $nonce = $principal->raw['nonce'] ?? null;

            if (! is_string($nonce) || ! hash_equals($expected->nonce, $nonce)) {
                Log::warning('cbox-id: OIDC nonce mismatch.', ['connection_id' => $model->id]);

                return $this->landing->failed(FederationFailure::Rejected, $label);
            }

            // THE NAME A PROVIDER SENDS ONCE, outside the assertion — Apple, and only
            // Apple, on the first authorization. This route SHADOWS the framework's, so
            // the merge the framework does was dead on this deployment: every Sign in
            // with Apple account was still created with a null name, permanently.
            $principal = $this->firstAuthorization->merge($model, $request, $principal);
        } catch (InvalidAssertion|ConnectionInactive|UnsafeFederationUrl $e) {
            Log::warning('cbox-id: OIDC login rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->landing->failed(FederationFailure::Rejected, $label);
        } catch (ConnectionException $e) {
            // The provider's token endpoint or key set did not answer. Nothing the person
            // did, and nothing they can fix — but "try again in a moment" is true.
            Log::warning('cbox-id: OIDC provider unreachable.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->landing->failed(FederationFailure::Unavailable, $label);
        }

        // CONNECTING FROM MY ACCOUNT rides this same callback — the connection has one
        // redirect URI — and said so before it left. See ConnectFromAccount.
        $intent = ConnectIntent::claim($model->id);

        if ($intent !== null) {
            return $this->connect->finish($intent, $model, $principal);
        }

        try {
            $session = $this->flow->completeLogin($model, $principal);
        } catch (InvalidAssertion|ConnectionInactive $e) {
            Log::warning('cbox-id: OIDC login rejected.', [
                'connection_id' => $model->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->landing->failed(FederationFailure::Rejected, $label);
        } catch (AccountInactive) {
            return $this->landing->failed(FederationFailure::AccountDisabled, $label);
        } catch (AccountExistsForEmail) {
            // The address is somebody's here and this identity is not linked to them yet.
            // Not a refusal, and never a silent merge: the link step asks them to prove it.
            return $this->linking->begin($request, $principal, $model, null, $model->name, (string) ($model->provider ?? 'sso'));
        }

        if ($this->through->connection()?->id === $model->id) {
            $session = $this->withUpstreamMethods($session, $principal);
        }

        // Plane-aware landing: a tenant host signs the subject in; the account host
        // signs an account MEMBER in, and refuses a subject that is not one.
        return $this->landing->land($request, $session);
    }

    /**
     * HOW THE PERSON SIGNED IN UPSTREAM, kept — for the connection this environment signs in
     * through, and only that one ({@see SignInThrough}).
     *
     * A federated session is recorded as `sso` and nothing else, which is AAL1: right for an
     * organization's identity provider this environment knows nothing about. The connection
     * an environment's whole sign-in goes through is different — its administrator chose it
     * as THE way in — and its id_token's `amr` says whether the person used a passkey or a
     * second factor there. Dropping that made every operator of the platform, who must be at
     * AAL2, prove a second factor again on every hop from an app where they had just done so.
     *
     * Only methods this platform knows are carried; anything else in the claim is ignored.
     */
    private function withUpstreamMethods(Session $session, FederatedPrincipal $principal): Session
    {
        $claimed = $principal->raw['amr'] ?? null;

        if (! is_array($claimed)) {
            return $session;
        }

        $known = array_values(array_filter(
            $claimed,
            static fn (mixed $method): bool => is_string($method) && AuthMethod::tryFrom($method) !== null,
        ));

        if ($known === []) {
            return $session;
        }

        $session->forceFill(['amr' => array_values(array_unique([...array_values((array) $session->amr), ...$known]))])->save();

        return $session;
    }
}
