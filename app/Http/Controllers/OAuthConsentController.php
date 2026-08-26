<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\SignInWithTicket;
use App\Platform\OAuth\PendingAuthorization;
use App\Platform\OAuth\PendingAuthorizations;
use App\Platform\ScopeCatalog;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * THE CONSENT SCREEN — the one page in this product that is both a protocol surface and a
 * user interface.
 *
 * `GET|POST /oauth/authorize` validates an authorization request against the registered
 * client, decides whether the person is allowed to answer it, and either shows them what
 * is being asked for or answers the client directly. Everything it refuses, it refuses in
 * the shape RFC 6749 and OIDC Core define, because the caller is software.
 *
 * THE VALIDATED REQUEST NEVER TOUCHES THE BROWSER. It is written to
 * {@see PendingAuthorizations} and the page carries an opaque id; approve and deny read it
 * back from there. Under Volt these were `#[Locked]` properties, and the attribute was the
 * only thing standing between a re-hydrated public property and an attacker swapping in an
 * unregistered `redirect_uri` after validation. Not holding the value at all is the
 * stronger version of the same idea.
 *
 * THE ROUTE IS DELIBERATELY NOT BEHIND `platform.auth`. OIDC Core §3.1.2.6 requires an
 * unauthenticated `prompt=none` request to answer the CLIENT with `login_required`, and an
 * auth middleware would redirect to the sign-in page before this could say so — which is
 * exactly what broke silent renew in every SPA library that loads `/authorize` in a hidden
 * iframe. So this authenticates for itself.
 */
final readonly class OAuthConsentController extends PageController
{
    /**
     * Slack on the `max_age` comparison, in seconds.
     *
     * Covers the one redirect between a re-authentication being recorded and the resumed
     * authorization request reading it, plus modest skew between the database clock that
     * stamps `sessions.created_at` and the PHP clock that compares it. See
     * {@see unmetAuthenticationRequirement()} for why a strict comparison made `max_age=0`
     * unsatisfiable.
     */
    private const MAX_AGE_SKEW_SECONDS = 60;

    public function show(
        Request $request,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
    ): Response|SymfonyResponse {
        /*
         * RFC 9126: if the client PUSHED its request, take the parameters from the
         * single-use `request_uri` rather than from the (untrusted, tamperable) query.
         */
        $pushed = null;
        $requestUri = $request->input('request_uri');
        $requestClientId = $request->input('client_id');

        if (is_string($requestUri) && $requestUri !== '' && is_string($requestClientId)) {
            $pushed = app(PushedAuthorizationRequests::class)->consume($requestClientId, $requestUri);

            if ($pushed === null) {
                return $this->failure('This authorization request has expired or was already used. Please start again.');
            }
        } elseif (config('cbox-id.oauth.require_par') === true) {
            // FAPI baseline: every authorization request must be pushed (RFC 9126), so raw
            // query-string requests are refused.
            return $this->failure('This server requires pushed authorization requests. Send the request to /oauth/par first.');
        }

        $from = static fn (string $key): mixed => $pushed[$key] ?? $request->input($key);

        $clientId = $from('client_id');
        $redirectUri = $from('redirect_uri');
        $responseType = $from('response_type');

        /*
         * Narrowed to ?string HERE rather than at each use. `state` is echoed back to the
         * client on every error branch below, and the PAR payload or the query string may
         * hold anything — a crafted `?state[]=x` makes it an array. Normalising once means
         * the RFC 6749 §4.1.2.1 echo cannot be handed a non-string.
         */
        $stateRaw = $from('state');
        $state = is_string($stateRaw) ? $stateRaw : null;

        $codeChallenge = $from('code_challenge');
        $codeChallengeMethod = $from('code_challenge_method') ?? 'S256';

        /*
         * ORDER MATTERS. RFC 6749 §4.1.2.1 splits errors in two: once the client and its
         * redirect_uri are known-good, an error must be RETURNED TO THE CLIENT as a
         * redirect carrying `error` and `state`; only an unknown client or an unregistered
         * redirect_uri may be shown as a page, because redirecting there would be an open
         * redirect.
         *
         * `response_type` used to be checked FIRST, so the code could not redirect even
         * when it should: an RP configured for hybrid flow, or one omitting PKCE, got a
         * human-readable HTML page, its callback never fired, and its SDK hung until
         * timeout with no error code. That is also an outright fail in the OpenID
         * basic-certification profile.
         */
        $client = is_string($clientId) && $clientId !== '' ? $clients->byClientId($clientId) : null;

        if (! $client instanceof Client) {
            return $this->failure('Unknown client. This application is not registered with Cbox ID.');
        }

        /*
         * The redirect_uri must exactly match one the client registered. Never redirect to
         * a URI we have not verified.
         *
         * `array_values()`: `redirect_uris` is a JSON cast, so a row written as a JSON
         * object rather than an array rehydrates with string keys. `redirectUriRegistered()`
         * asks for a list, and the re-key is what makes that true — not decoration.
         */
        if (! is_string($redirectUri) || ! $this->redirectUriRegistered($redirectUri, array_values($client->redirect_uris))) {
            return $this->failure('The redirect URI does not match any registered for this application.');
        }

        // From here the redirect_uri is verified, so every remaining error goes BACK to the
        // client in the RFC-defined shape rather than being rendered.
        if ($responseType !== 'code') {
            return $this->redirectError($redirectUri, 'unsupported_response_type', $state,
                'Only the authorization code flow is supported.');
        }

        if (! is_string($codeChallenge) || $codeChallenge === '') {
            return $this->redirectError($redirectUri, 'invalid_request', $state,
                'A PKCE code_challenge is required.');
        }

        /*
         * AND IT MUST BE THE RIGHT SHAPE. RFC 7636 §4.2: an S256 challenge is base64url of
         * a SHA-256 digest — 43 characters of the unreserved set. The issuer refuses
         * anything else, so without this check a client sending a placeholder got a consent
         * screen, pressed Allow, and hit a 500 at the moment the code was minted: the error
         * belongs HERE, at /authorize, where a developer is looking and where the protocol
         * has a way to say it.
         */
        if (preg_match('/^[A-Za-z0-9\-._~]{43}$/', $codeChallenge) !== 1) {
            return $this->redirectError($redirectUri, 'invalid_request', $state,
                'The code_challenge must be the base64url-encoded SHA-256 digest of your code_verifier.');
        }

        if ($codeChallengeMethod !== 'S256') {
            return $this->redirectError($redirectUri, 'invalid_request', $state,
                'Only the S256 code_challenge_method is supported.');
        }

        /*
         * RFC 6749 §3.3 / §4.1.2.1: a scope the client is not registered for is
         * `invalid_scope`, and the error belongs at /authorize where the developer can see
         * it. Letting it through meant the request succeeded, the issuer quietly filtered
         * the scope down at mint time, and the client's next API call 403'd with nothing
         * anywhere to explain why.
         *
         * A client that registered NO scopes has declared no surface at all, so there is
         * nothing to check it against — the issuer already grants it nothing.
         */
        $scopeParam = $from('scope');
        $requestedScopes = $this->parseScopes(is_string($scopeParam) ? $scopeParam : '');
        $unregistered = $client->scopes === []
            ? []
            : array_values(array_filter($requestedScopes, static fn (string $scope): bool => ! $client->allows($scope)));

        if ($unregistered !== []) {
            return $this->redirectError($redirectUri, 'invalid_scope', $state,
                'This application is not registered for the requested scope(s): '.implode(' ', $unregistered).'.');
        }

        $resourceParam = $from('resource');
        $acrParam = $from('acr_values');
        $nonceParam = $from('nonce');

        $authorization = new PendingAuthorization(
            clientId: $client->client_id,
            clientName: $client->name,
            clientOwner: $this->owner($client),
            redirectUri: $redirectUri,
            scopes: $requestedScopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            state: $state,
            nonce: is_string($nonceParam) ? $nonceParam : null,
            /*
             * RFC 8707: the resource server this authorization is FOR.
             *
             * It used to be read only at the token endpoint, which meant nothing recorded
             * what the person had agreed to — so a client could name one audience here and
             * a different one at redemption, and receive a token asserting the second.
             * Captured here and bound to the code, the two can no longer disagree.
             */
            resource: is_string($resourceParam) && trim($resourceParam) !== '' ? trim($resourceParam) : null,
            maxAge: $this->parseMaxAge($from('max_age')),
            acrValues: is_string($acrParam) && trim($acrParam) !== '' ? trim($acrParam) : null,
            /*
             * The consumed PAR payload, kept because the request may still need to be
             * RESUMED after sign-in or an account switch. Without it a resume rebuilt a
             * plain query URL, and a FAPI deployment (`require_par`) then refused its own
             * resumed request — every unauthenticated person dead-ended on "this server
             * requires pushed authorization requests".
             */
            pushedPayload: $pushed,
        );

        /*
         * OIDC `prompt` handling. `select_account` sends the person to the account chooser;
         * `login` goes straight to add-another-account. Neither signs anyone out — the
         * chosen account becomes active and the request resumes, carrying `reauthed=1` so
         * re-entry does not loop.
         */
        $promptParam = $from('prompt');
        $prompts = is_string($promptParam) ? array_values(array_filter(explode(' ', $promptParam))) : [];
        $silent = in_array('none', $prompts, true);
        $reauthed = in_array($from('reauthed'), ['1', 'true'], true);

        /*
         * A LOGIN TICKET stands in for the session cookie a cross-origin page cannot carry.
         * It is what an embedded sign-in form receives instead of tokens: the credential
         * check already happened at `/frontend/v1/sign-in`, and this turns it into a session
         * so the ORDINARY flow below runs — same consent, same PKCE, same code.
         *
         * REDEEMED BEFORE ANYTHING LOOKS AT THE COOKIE, and that ordering is the whole
         * point. It used to sit inside `if (! check())`, so a browser already holding a
         * session for Alice ignored a ticket that had just authenticated Bob: the flow ran
         * as Alice, skipped consent for a first-party client, and handed the relying party
         * an id_token for somebody who never signed in on that page.
         */
        $ticket = $from('login_ticket');

        if (is_string($ticket) && $ticket !== '') {
            $refusal = $this->redeemTicket($request, $ticket, $authorization, $silent);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $me = app(CurrentUser::class);

        if (! $me->check()) {
            if ($silent) {
                return $this->redirectError($redirectUri, 'login_required', $state,
                    'The user is not signed in and prompt=none forbids interaction.');
            }

            return $this->interrupt($request, $authorization, route('login'));
        }

        /*
         * THE ORGANIZATION'S OWN SIGN-IN RULES. A forced password change and an MFA mandate
         * hold every console page; they must hold this one too, or the endpoint that mints
         * tokens becomes the way around the policy the console enforces.
         *
         * Enforced here rather than in the auth middleware, which is where it was first put:
         * the middleware can only read `prompt` from the query string, while this resolves
         * it from the PAR payload first. A silent-renew iframe using PAR or the POST binding
         * therefore got the password-change page instead of the OIDC error — and under
         * `require_par` that is the only legal way to send prompt=none at all.
         */
        $hold = $this->unsatisfiedAuthPolicy($me->subject()?->id);

        if ($hold !== null) {
            if ($silent) {
                return $this->redirectError($redirectUri, 'interaction_required', $state, $hold['reason']);
            }

            return $this->interrupt($request, $authorization, route($hold['route']));
        }

        if (! $reauthed && in_array('select_account', $prompts, true)) {
            return $this->interrupt($request, $authorization, route('accounts'));
        }

        if (! $reauthed && in_array('login', $prompts, true)) {
            return $this->interrupt($request, $authorization, route('accounts.add'));
        }

        /*
         * STEP-UP. `max_age` and `acr_values` are the two controls OIDC gives a relying
         * party to demand a FRESH or a STRONGER authentication before a sensitive operation
         * — a payment, an admin grant. Both were accepted and ignored: a client calling
         * `login({maxAge: 0})` got a code minted from a day-old session carrying the
         * ORIGINAL auth_time, and one asking for aal2 got a password-only user authorized
         * and an aal1 id_token, with no way to tell either had happened.
         *
         * Coming back still unsatisfied means the requirement is genuinely unmeetable here,
         * and the honest answer is an error to the client rather than a token quietly
         * asserting less than was demanded — so `reauthed` is never trusted as satisfaction.
         */
        $unmet = $this->unmetAuthenticationRequirement($authorization, $me->session());

        if ($unmet !== null) {
            if ($reauthed || $silent) {
                return $this->redirectError($redirectUri, $unmet[0], $state, $unmet[1]);
            }

            return $this->interrupt($request, $authorization, route('accounts.add'));
        }

        /*
         * FIRST-PARTY CONSENT-SKIP: an organization's own trusted app — or a platform-owned
         * first-party client — authorizes without a prompt. STRICTLY organization-scoped: a
         * first-party client owned by ANOTHER organization still prompts, so it can never
         * silently mint a code for a different tenant's user. The issuing path re-asserts
         * every invariant, so this skips the screen and never the checks.
         */
        $skipConsent = $client->first_party === true
            && ($client->organization_id === null || $client->organization_id === $me->organizationId());

        if ($silent && ! $skipConsent) {
            /*
             * Through redirectError() so this branch carries the RFC 9207 `iss` too —
             * building the redirect directly here was the one error path that omitted it,
             * and a mix-up-hardened client checks it on errors as well.
             */
            return $this->redirectError($redirectUri, 'interaction_required', $state,
                'User interaction is required to authorize this request.');
        }

        if ($skipConsent) {
            return $this->issue($request, $authorization, $clients, $codes);
        }

        $id = $pending->put($request, $authorization);

        return $this->page('oauth/consent', 'Authorize', [
            'client' => [
                'name' => $authorization->clientName,
                'owner' => $authorization->clientOwner,
            ],
            'me' => [
                'name' => $me->name(),
                'email' => $me->email(),
                'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
            ],
            /*
             * FROM THE CATALOG, not a second copy of it. The old map held four strings and
             * fell back to the raw scope key for everything else — so a person deciding
             * whether to allow an app was shown the literal word "groups", with nothing to
             * say what it meant, on the most end-user-facing page in the product.
             */
            'scopes' => $this->scopeRows($authorization->scopes),
            'redirectHost' => parse_url($authorization->redirectUri, PHP_URL_HOST),
            'approveHref' => route('oauth.authorize.approve', $id),
            'denyHref' => route('oauth.authorize.deny', $id),
        ]);
    }

    public function approve(
        Request $request,
        string $authorization,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
    ): Response|SymfonyResponse {
        $found = $pending->find($request, $authorization);

        if ($found === null) {
            return $this->failure('This authorization request can no longer be completed. Please start again.');
        }

        // Spent either way: a second click on a stale tab must not mint a second code from
        // one consent.
        $pending->forget($request, $authorization);

        return $this->issue($request, $found, $clients, $codes);
    }

    public function deny(Request $request, string $authorization, PendingAuthorizations $pending): Response|SymfonyResponse
    {
        $found = $pending->find($request, $authorization);

        if ($found === null) {
            return $this->failure('This authorization request can no longer be completed. Please start again.');
        }

        $pending->forget($request, $authorization);

        /*
         * RFC 9207 §2: `iss` belongs on authorization responses INCLUDING error ones, and a
         * mix-up-hardened client MUST reject a response without it. Omitting it here meant
         * somebody pressing "Deny" got an oauth4webapi throw instead of the "you declined"
         * screen the relying party wrote.
         */
        $params = ['error' => 'access_denied', 'iss' => app(IssuerResolver::class)->issuer()];

        if ($found->state !== null) {
            $params['state'] = $found->state;
        }

        return $this->leave($this->buildRedirect($found->redirectUri, $params));
    }

    /**
     * Mint the code and send the browser back to the client.
     *
     * EVERY INVARIANT IS RE-ASSERTED HERE rather than trusted from the render. The render
     * ran on another request, against whatever session was active then; the code is minted
     * from whatever is active now.
     */
    private function issue(
        Request $request,
        PendingAuthorization $authorization,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
    ): Response|SymfonyResponse {
        /*
         * NO IMPERSONATION CHECK HERE, and its absence is deliberate.
         *
         * Never mint a credential on someone else's behalf while wearing their session —
         * and this method used to say so itself, because `ImpersonationCallGuard` hung off
         * Livewire's `call` event, which never saw `mount()`, and `mount()` reached
         * issuance directly whenever consent was skipped for a first-party client. Half the
         * paths in were guarded by the route and half by the seam, so the method had to
         * cover both.
         *
         * Every path in is a route now — the screen, the approval, the denial — and each
         * carries `BlockDuringImpersonation`. A copy here would be a branch no request can
         * reach, and an unreachable guard reads as the thing holding the line while
         * something else quietly does. ImpersonationReadOnlyTest asks the routes.
         */
        $me = app(CurrentUser::class);

        if (! $me->check()) {
            return $this->failure('This authorization request can no longer be completed. Please start again.');
        }

        /*
         * And never past a hold that landed after the page was drawn. A consent page opened
         * before an administrator mandated MFA, or before a password expired, must not mint
         * a code afterwards: somebody keeps a tab open, the policy changes, they click Allow.
         */
        if ($this->unsatisfiedAuthPolicy($me->subject()?->id) !== null) {
            return $this->failure('Your account needs attention before you can continue. Please sign in again.');
        }

        // Defence in depth: the redirect_uri must still be registered to the client, and
        // PKCE must still be S256.
        $client = $clients->byClientId($authorization->clientId);

        if (! $client instanceof Client
            || ! $this->redirectUriRegistered($authorization->redirectUri, array_values($client->redirect_uris))
            || $authorization->codeChallenge === ''
            || $authorization->codeChallengeMethod !== 'S256') {
            return $this->failure('This authorization request can no longer be completed. Please start again.');
        }

        /*
         * NO ORGANIZATION-STATUS CHECK HERE, and its absence is deliberate.
         *
         * An organization that is no longer live — suspended or deleted — cannot authorize
         * applications or mint tokens, and this method used to say so itself. It had to
         * under Volt: approving was a component action on the shared `/livewire/update`
         * endpoint, which route middleware never saw.
         *
         * Every path into this method is a route now, and {@see \App\Http\Middleware\Authenticate}
         * asks {@see \App\Platform\OrganizationAccess} of every authenticated request —
         * /authorize included — so a copy here would be a branch no request can reach. An
         * unreachable guard is worse than none: it reads as the thing holding the line while
         * something else quietly does. OAuthAuthorizeTest asks the door that answers.
         */
        $session = $me->session();

        if ($this->unmetAuthenticationRequirement($authorization, $session) !== null) {
            return $this->failure('This application requires a more recent or stronger sign-in. Please start again.');
        }

        $code = $codes->issue(
            $authorization->clientId,
            $me->id(),
            $me->organizationId(),
            $authorization->redirectUri,
            /*
             * `array_values()` on `amr`: a JSON column is not guaranteed to rehydrate as a
             * list, and the issued grant is serialised straight back to JSON — an object
             * there is a wire-format change for every client that reads an array.
             */
            $authorization->scopes,
            $authorization->codeChallenge,
            $authorization->codeChallengeMethod,
            $authorization->nonce,
            $session?->created_at?->getTimestamp(),
            $session !== null ? array_values($session->amr) : [],
            $authorization->resource,
        );

        /*
         * RFC 9207: return the issuer in the authorization response so the client can detect
         * a mix-up (a code minted by a different AS than it expects). Resolved the SAME way
         * discovery and the id_token do — reading `config('cbox-id.issuer')` here returned
         * the platform APEX, so a tenant on its own host advertised one issuer and returned
         * another, and a mix-up-hardened RP aborts the callback on that.
         */
        $params = ['code' => $code, 'iss' => app(IssuerResolver::class)->issuer()];

        if ($authorization->state !== null) {
            $params['state'] = $authorization->state;
        }

        return $this->leave($this->buildRedirect($authorization->redirectUri, $params));
    }

    /**
     * Send the person somewhere they can satisfy a requirement, and come back.
     *
     * The resume URL is stashed as the intended destination, or they land on the console
     * and the relying party's callback never fires.
     */
    private function interrupt(Request $request, PendingAuthorization $authorization, string $to): RedirectResponse
    {
        $request->session()->put('url.intended', $this->resumeUrl($authorization));

        return redirect()->to($to);
    }

    /**
     * Rebuild this authorization request as a URL to resume after a re-authentication —
     * without `prompt`, and with a loop guard.
     */
    private function resumeUrl(PendingAuthorization $authorization): string
    {
        /*
         * Re-push the original payload under a FRESH single-use request_uri, so the resumed
         * request is a genuine PAR request rather than a query-string one that `require_par`
         * must refuse. Re-pushing (not reusing) keeps the single-use property intact — and
         * marking the resume as "already pushed" via a flag would let anyone bypass PAR by
         * adding it to a URL.
         */
        if ($authorization->pushedPayload !== null) {
            $client = app(ClientRegistry::class)->byClientId($authorization->clientId);

            if ($client !== null) {
                $repushed = app(PushedAuthorizationRequests::class)->push($client, $authorization->pushedPayload);

                return route('oauth.authorize', [
                    'client_id' => $authorization->clientId,
                    'request_uri' => $repushed['request_uri'],
                    'reauthed' => '1',
                ]);
            }
        }

        return route('oauth.authorize', array_filter([
            'client_id' => $authorization->clientId,
            'redirect_uri' => $authorization->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $authorization->scopes),
            'state' => $authorization->state,
            'code_challenge' => $authorization->codeChallenge,
            'code_challenge_method' => $authorization->codeChallengeMethod,
            'nonce' => $authorization->nonce,
            /*
             * Re-stated so the step-up requirement survives the round trip. `max_age=0` is
             * the whole point of the parameter ("re-authenticate now"), so it is carried as
             * a string and never filtered out as falsy.
             */
            'max_age' => $authorization->maxAge !== null ? (string) $authorization->maxAge : null,
            'acr_values' => $authorization->acrValues,
            /*
             * RFC 8707. Dropped here until it was noticed, which quietly un-did the
             * confused-deputy fix the token endpoint documents: a code minted on the way
             * back from the sign-in carried `resource = null`, so the binding check there
             * no-opped and the client's own value at REDEMPTION time was taken instead.
             */
            'resource' => $authorization->resource,
            'reauthed' => '1',
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * Redeem a login ticket, or answer the client if it cannot stand.
     *
     * @return SymfonyResponse|null null when the flow may continue
     */
    private function redeemTicket(
        Request $request,
        string $ticket,
        PendingAuthorization $authorization,
        bool $silent,
    ): ?SymfonyResponse {
        /*
         * prompt=none is the SILENT-RENEW path, and OIDC Core §3.1.2.1 lets it succeed only
         * when the End-User "is already authenticated". Redeeming a ticket creates a session
         * inside the request — no UI is shown, so the letter about interaction is kept, but
         * the precondition is not, and an RP reading a successful silent renew as proof of a
         * pre-existing SSO session would be drawing a conclusion we made up milliseconds ago
         * on a page it does not control. So the two do not combine.
         */
        if ($silent) {
            return $this->redirectError($authorization->redirectUri, 'login_required', $authorization->state,
                'A login_ticket cannot satisfy prompt=none: the user was not already authenticated.');
        }

        $established = app(SignInWithTicket::class)->establish($request, $ticket);

        if ($established || ! app(CurrentUser::class)->check()) {
            return null;
        }

        /*
         * A ticket that was presented and refused, over a session that IS signed in. Two
         * very different things arrive here, and redemption cannot tell them apart — its
         * conditional UPDATE is what makes a ticket single-use:
         *
         *  - the same person pressing reload on this consent screen, or arriving back
         *    through history. Their ticket was spent by the render they are refreshing.
         *    Aborting their authorization for that would be a bug wearing a security
         *    control's clothes.
         *  - a ticket naming somebody else, over a cookie naming this person. That is the
         *    wrong-principal case, and it stays refused.
         *
         * So the ticket's subject decides, read from the row rather than from the redemption.
         */
        if (app(LoginTickets::class)->subjectOf($ticket) === app(CurrentUser::class)->id()) {
            return null;
        }

        return $this->redirectError($authorization->redirectUri, 'access_denied', $authorization->state,
            'The login_ticket could not be redeemed.');
    }

    /**
     * The organization's sign-in rule this subject has not satisfied, or null.
     *
     * @return array{route: string, reason: string}|null
     */
    private function unsatisfiedAuthPolicy(?string $subjectId): ?array
    {
        if ($subjectId === null) {
            return null;
        }

        if (app(AdminPasswords::class)->requiresChange($subjectId)
            || app(PasswordExpiry::class)->hasExpired($subjectId)) {
            return [
                'route' => 'password.change',
                'reason' => 'The user must change their password before authorizing.',
            ];
        }

        if (app(MfaMandate::class)->requiresEnrolment($subjectId)) {
            return [
                'route' => 'account',
                'reason' => 'The user must enrol a second factor before authorizing.',
            ];
        }

        return null;
    }

    /**
     * The OIDC error to return when this session cannot satisfy the request's
     * authentication requirements, or null when it can.
     *
     * @return array{0: string, 1: string}|null [error code, description]
     */
    private function unmetAuthenticationRequirement(PendingAuthorization $authorization, ?Session $session): ?array
    {
        /*
         * `max_age`: compare the session's own AGE against the ceiling the client set. A
         * session we cannot date is treated as too old — fail closed.
         *
         * The comparison is `age > maxAge`, not `authTime < now - maxAge`, and it carries a
         * small skew allowance. With `max_age=0` the strict form reduces to "authenticated
         * strictly before this instant", which is true of EVERY session that exists —
         * including the one just created — so the round trip came back still unsatisfied and
         * the request died with `login_required`. `login({maxAge: 0})`, the case the
         * parameter exists for, could therefore never succeed.
         */
        if ($authorization->maxAge !== null) {
            $authTime = $session?->created_at?->getTimestamp();

            // now(), not time(): one clock for the whole application, and the only one a
            // test can move.
            if (! is_int($authTime) || (now()->getTimestamp() - $authTime) > ($authorization->maxAge + self::MAX_AGE_SKEW_SECONDS)) {
                return ['login_required', 'The existing authentication is older than the requested max_age.'];
            }
        }

        /*
         * `acr_values`: the strongest class named that this server asserts. Anything it does
         * not assert is ignored — the parameter is an ordered list of ACCEPTABLE classes, so
         * a client may legitimately name another IdP's.
         */
        $required = AuthenticationContextClass::fromRequest($authorization->acrValues);

        if ($required !== null && ! $required->isSatisfiedBy($session !== null ? array_values($session->amr) : [])) {
            /*
             * RFC 9470 §5: when it is the AUTHORIZATION request that cannot be satisfied,
             * the error is `unmet_authentication_requirements`.
             * `insufficient_user_authentication` is the RFC 9470 §3 `WWW-Authenticate`
             * challenge a PROTECTED RESOURCE returns, so an RP implementing the OIDF step-up
             * pattern branches on the former and falls through to a generic error page on
             * the latter.
             */
            return ['unmet_authentication_requirements', 'The requested authentication context ('.$required->value.') was not met by this session.'];
        }

        return null;
    }

    /**
     * Exact match, EXCEPT that a loopback redirect may use any port.
     *
     * RFC 8252 §7.3: a native app binds an ephemeral port at runtime, so the port it
     * registered once is not the port it listens on next time. A byte-exact comparison
     * rejected every such client on its second run. Scheme, host and path still must match
     * exactly — only the port floats, and only for 127.0.0.1 / [::1], never a remote host.
     *
     * @param  list<string>  $registered
     */
    private function redirectUriRegistered(string $candidate, array $registered): bool
    {
        if (in_array($candidate, $registered, true)) {
            return true;
        }

        $parts = parse_url($candidate);

        /*
         * parse_url returns an IPv6 host WITH its brackets ("[::1]"), so comparing against
         * the bare literal never matched and every [::1] native client fell back to
         * byte-exact matching — failing on its second run. It failed closed, but the
         * documented behaviour was untrue.
         */
        $host = trim($parts['host'] ?? '', '[]');

        if (! in_array($host, ['127.0.0.1', '::1'], true) || ($parts['scheme'] ?? '') !== 'http') {
            return false;
        }

        foreach ($registered as $uri) {
            $registeredParts = parse_url($uri);

            if (($registeredParts['scheme'] ?? '') === 'http'
                && trim($registeredParts['host'] ?? '', '[]') === $host
                && ($registeredParts['path'] ?? '') === ($parts['path'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return an RFC 6749 §4.1.2.1 error to the CLIENT rather than rendering a page.
     *
     * Only safe once the redirect_uri has been matched against the client's registered set
     * — before that, redirecting would be an open redirect, which is why an unknown client
     * or a bad redirect_uri stay as rendered pages.
     */
    private function redirectError(string $redirectUri, string $error, ?string $state, string $description): SymfonyResponse
    {
        $params = ['error' => $error, 'error_description' => $description];

        // RFC 6749 §4.1.2.1: `state` MUST be echoed when the request carried one — the
        // client correlates the failure with the attempt it started.
        if (is_string($state) && $state !== '') {
            $params['state'] = $state;
        }

        // RFC 9207: the issuer belongs on the error response too, so a mix-up-hardened
        // client can tell WHICH server refused it.
        $params['iss'] = app(IssuerResolver::class)->issuer();

        return $this->leave($this->buildRedirect($redirectUri, $params));
    }

    /**
     * SEND THE BROWSER TO THE CLIENT, whichever way it arrived.
     *
     * `Inertia::location()` and not `redirect()->away()`, and the difference is not
     * cosmetic. Approving is an Inertia visit — an XHR — and a 302 to another origin is
     * something the client library cannot follow: it would fetch the relying party's
     * callback itself and hand back HTML the page has no idea what to do with. The 409 +
     * `X-Inertia-Location` this produces is the protocol's own "leave the app" answer, and
     * the browser performs a real navigation.
     *
     * On an ORDINARY request — the initial GET that skips consent for a first-party client,
     * or an RFC 6749 error returned before any page is drawn — the same call yields a plain
     * redirect. Both paths matter and each hides the other's failure: the consent-skip is
     * the one a native app actually takes, and pressing Approve is the one everybody else
     * does.
     *
     * It also carries a PRIVATE-USE SCHEME, which is why this is not `redirect()->to()`.
     * Laravel's UrlGenerator runs `filter_var($path, FILTER_VALIDATE_URL)` to decide
     * whether a string is already a URL, and that rejects `com.example.app:/oauth/callback`
     * — so the URI was treated as a relative path, the app root was prepended, and the
     * authorization code was stranded in a URL the client never sees.
     */
    private function leave(string $url): SymfonyResponse
    {
        return $this->inertia->location($url);
    }

    /** The page shown when the request cannot be answered by redirecting anywhere. */
    private function failure(string $message): Response
    {
        return $this->page('oauth/consent', 'Authorization failed', ['error' => $message]);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function buildRedirect(string $redirectUri, array $params): string
    {
        /*
         * The registered URI is kept BYTE-FOR-BYTE up to the query, and only the query is
         * rebuilt.
         *
         * The old version assembled `scheme.'://'.host`, which assumes every redirect URI
         * has an authority. A native app's does not: RFC 8252 §7.1 registers
         * `com.example.app:/oauth/callback` — scheme and path, no authority at all. So
         * parse_url returned no host, the hardcoded `//` was emitted anyway, and the result
         * was `com.example.app:///oauth/callback` with three slashes: not what the client
         * registered, and not what its URL handler is listening for.
         *
         * We already matched this exact string against the registered set, so the only
         * correct transformation is to append to it — any rewrite is a chance to produce
         * something that was never registered.
         */
        $uri = $redirectUri;

        /*
         * A fragment is forbidden on a redirect URI (RFC 6749 §3.1.2) and we do not accept
         * one, but splitting it off first means a stray one can never end up in the middle
         * of the query we build.
         */
        $fragment = null;

        if (($hash = mb_strpos($uri, '#')) !== false) {
            $fragment = mb_substr($uri, $hash + 1);
            $uri = mb_substr($uri, 0, $hash);
        }

        $existing = [];
        $base = $uri;

        if (($mark = mb_strpos($uri, '?')) !== false) {
            $base = mb_substr($uri, 0, $mark);
            parse_str(mb_substr($uri, $mark + 1), $existing);
        }

        $url = $base.'?'.http_build_query(array_merge($existing, $params));

        return $fragment === null ? $url : $url.'#'.$fragment;
    }

    /**
     * OIDC Core §3.1.2.1 `max_age`: a non-negative number of seconds. A malformed value is
     * ignored rather than refused — it is a request, not a credential.
     */
    private function parseMaxAge(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * @param  list<string>  $scopes
     * @return list<array{scope: string, label: string}>
     */
    private function scopeRows(array $scopes): array
    {
        $labels = app(ScopeCatalog::class)->consentLabels();

        return array_map(
            static fn (string $scope): array => ['scope' => $scope, 'label' => $labels[$scope] ?? $scope],
            $scopes,
        );
    }

    private function owner(Client $client): string
    {
        if ($client->organization_id !== null) {
            return app(Organizations::class)->find($client->organization_id)->name
                ?? 'an organization that no longer exists';
        }

        $platformName = config('app.name');

        return is_string($platformName) && $platformName !== '' ? $platformName : 'Cbox ID';
    }

    /**
     * @return list<string>
     */
    private function parseScopes(string $scope): array
    {
        return array_values(array_filter(
            explode(' ', trim($scope)),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
