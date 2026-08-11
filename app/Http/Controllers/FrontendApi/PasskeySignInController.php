<?php

declare(strict_types=1);

namespace App\Http\Controllers\FrontendApi;

use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\PasskeyChallenges;
use App\Platform\RiskGuard;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Identity\Exceptions\ClonedAuthenticator;
use Cbox\Id\Identity\Exceptions\InvalidAssertionResponse;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signing in with a passkey, from a page that drew its own button.
 *
 * The last credential type the embedded channel was missing, and the one customers ask for
 * first — a sign-in box that redirects for passkeys is a sign-in box that redirects.
 *
 * THE CHALLENGE TRAVELS AS A HANDLE. WebAuthn is two requests: one that hands out a random
 * challenge and one that returns the authenticator's signature over it. The hosted form
 * keeps the challenge in the session between them; a cross-origin page has no session
 * cookie, so it carries an opaque handle instead. See {@see PasskeyChallenges} for why
 * that lives in the cache while login tickets live in a table.
 *
 * THE RELYING PARTY ID IS OURS, NOT THE PAGE'S. WebAuthn binds an assertion to the origin
 * that requested it, which is what makes a passkey phishing-resistant — and it means an
 * embedded button on `acme.com` still authenticates against THIS issuer's rpId. A customer
 * whose page is on another registrable domain cannot use this; that is WebAuthn working as
 * designed, not a limit worth engineering around, and the answer for them is the hosted
 * page or a subdomain of ours. Said out loud because it is the first thing that surprises
 * somebody integrating.
 *
 * It hands back a login ticket like every other route into this channel, so what happens
 * after a passkey is indistinguishable from what happens after a password.
 */
class PasskeySignInController
{
    public function __construct(
        private readonly PasskeyChallenges $challenges,
        private readonly LoginTickets $tickets,
        private readonly Passkeys $passkeys,
        private readonly RiskGuard $risk,
        private readonly RelyingParties $relyingParties,
    ) {}

    /**
     * Hand out a challenge for the browser to sign.
     *
     * `allowCredentials` is deliberately empty: this is a discoverable-credential flow, so
     * the authenticator offers whichever passkey it holds for this relying party and the
     * page never has to say who is signing in. Asking for an email first — to look up
     * which credentials exist — would be the enumeration oracle in a new costume.
     */
    public function options(Request $request): JsonResponse
    {
        $key = $request->attributes->get('cbox_publishable_key');

        if (! $key instanceof PublishableKey) {
            return new JsonResponse(['status' => 'invalid'], 401);
        }

        $challenge = random_bytes(32);

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'challenge_token' => $this->challenges->issue($key, $challenge),
            // The SAME relying party the hosted flow uses, resolved rather than derived
            // from a URL: a passkey registered on one rpId cannot answer a challenge on
            // another, so computing it twice in two ways is how an embedded button quietly
            // stops recognising credentials the hosted page created.
            'rpId' => $this->relyingParties->current()->id,
            'userVerification' => 'required',
            'allowCredentials' => [],
            'timeout' => 60000,
        ]);
    }

    /**
     * Verify the assertion and mint a ticket.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->attributes->get('cbox_publishable_key');
        $handle = $request->input('challenge_token');
        $credentialId = $request->string('id')->toString();

        if (! $key instanceof PublishableKey || ! is_string($handle) || $handle === '' || $credentialId === '') {
            return $this->refuse();
        }

        // Checked before the assertion is verified, as the hosted flow does: a passkey is
        // phishing-resistant, so an elevated-but-not-rejected outcome needs no step-up, but
        // a hard block must stop before anything is established.
        if ($this->risk->shouldBlock($this->risk->assess($request, 'login'))) {
            return $this->refuse();
        }

        $challenge = $this->challenges->claim($key, $handle);

        if ($challenge === null) {
            return $this->refuse();
        }

        try {
            $subjectId = $this->passkeys->authenticate($credentialId, $challenge, $request->getContent());
        } catch (UnknownCredential|InvalidAssertionResponse|ClonedAuthenticator) {
            // One answer for all three. A cloned authenticator is a genuinely different
            // event and worth an audit entry — which `authenticate()` writes — but telling
            // an anonymous caller which of the three happened describes the credential
            // estate to somebody who has not proved anything.
            return $this->refuse();
        }

        // `webauthn` alone, and no `pwd`: a passkey IS the factor, and recording a password
        // that nobody typed would put an untruth on the session's `amr` — which decides
        // `acr` and, since this week, the SAML assertion's context class too.
        return new JsonResponse([
            'status' => 'ok',
            'login_ticket' => $this->tickets->mint($key, $subjectId, ['webauthn']),
            'expires_in' => 60,
        ]);
    }

    private function refuse(): JsonResponse
    {
        return new JsonResponse(['status' => 'invalid'], 401);
    }
}
