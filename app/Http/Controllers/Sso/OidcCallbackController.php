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
use Cbox\Id\Federation\OidcClient;
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
        private readonly PlatformAuth $auth,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $model = $this->connections->byId($connection);

        if ($model === null || ! $model->isActive() || $model->type !== ConnectionType::Oidc) {
            return $this->failed('That single sign-on connection is no longer active. Ask your IT administrator to re-enable it.');
        }

        // Pulled, not read: replaying a callback finds nothing stashed and fails closed.
        $stashed = $request->session()->pull('oidc.'.$model->id);
        $expectedState = is_array($stashed) && is_string($stashed['state'] ?? null) ? $stashed['state'] : null;
        $expectedNonce = is_array($stashed) && is_string($stashed['nonce'] ?? null) ? $stashed['nonce'] : null;

        $state = $request->string('state')->toString();
        $code = $request->string('code')->toString();

        // CSRF: the state must be the one we issued for THIS browser session. A stale
        // state is routine (a bookmarked callback, the back button), so send the user
        // back to sign in rather than showing them an error page.
        if ($expectedState === null || $code === '' || ! hash_equals($expectedState, $state)) {
            return $this->failed('That sign-in link has expired. Please sign in again.');
        }

        try {
            $idToken = $this->client->exchangeCode($model, $code, url('/sso/oidc/'.$model->id.'/callback'));
            $principal = $this->validator->validate($model, $idToken);

            $nonce = $principal->raw['nonce'] ?? null;

            if ($expectedNonce === null || ! is_string($nonce) || ! hash_equals($expectedNonce, $nonce)) {
                Log::warning('cbox-id: OIDC nonce mismatch.', ['connection_id' => $model->id]);

                return $this->failed('We could not verify that sign-in. Please try again.');
            }

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

        $this->auth->adopt($request, $session);

        return redirect()->intended(route('dashboard'));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['identifier' => $message]);
    }
}
