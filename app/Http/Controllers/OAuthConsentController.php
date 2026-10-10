<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OAuth\ChooseOrganizationRequest;
use App\Http\Requests\OAuth\CreateOrganizationRequest;
use App\Platform\CurrentUser;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\SignInWithTicket;
use App\Platform\OAuth\ConsentScopes;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\OAuth\Enums\AuthorizationPrompt;
use App\Platform\OAuth\Exceptions\OrganizationCreationRefused;
use App\Platform\OAuth\PendingAuthorization;
use App\Platform\OAuth\PendingAuthorizations;
use App\Platform\OAuth\RootMcpOAuth;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\PlatformAuth;
use App\Platform\SignupPolicy;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use App\Platform\SupportAccess\Exceptions\SupportRequestRefused;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\AudienceResolver;
use Cbox\Id\OAuthServer\Contracts\AuthorizationClients;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Exceptions\InvalidAuthenticationRequirement;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadataDocument;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\ResourceParameter;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationRequirement;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizationClient;
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
                return $this->failure(__('oauth.failure.expired'));
            }
        } elseif (config('cbox-id.oauth.require_par') === true) {
            // FAPI baseline: every authorization request must be pushed (RFC 9126), so raw
            // query-string requests are refused.
            return $this->failure(__('oauth.failure.par_required'));
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
        /*
         * WHICH CLIENT — registered (by an administrator, or by itself under RFC 7591), or
         * described by a client ID metadata document at the https URL it gave as its id
         * ({@see AuthorizationClients}). A document that cannot be used is rendered, never
         * redirected: there is no verified redirect URI to send the error to yet.
         */
        try {
            $authorizing = is_string($clientId) && $clientId !== '' ? app(AuthorizationClients::class)->resolve($clientId) : null;
        } catch (InvalidClientMetadataDocument) {
            return $this->failure(__('oauth.failure.client_document'));
        }

        if ($authorizing === null) {
            return $this->failure(__('oauth.failure.unknown_client'));
        }

        $client = $authorizing->client;

        /*
         * The redirect_uri must exactly match one the client registered. Never redirect to
         * a URI we have not verified.
         */
        if (! is_string($redirectUri) || ! $this->redirectAllowed($authorizing, $redirectUri)) {
            return $this->failure(__('oauth.failure.redirect_mismatch'));
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

        /*
         * RFC 8707: the resource server this authorization is FOR, read the way the token
         * and PAR endpoints read it ({@see ResourceParameter}: one value, well-formed, a
         * repeated one refused) — and then put to the SAME audience resolver the token
         * endpoint will ask, so this page can never agree to what redemption would refuse.
         * A self-registered client asking for a resource that does not take such clients,
         * or scopes that span two APIs with nothing to choose between them, hears so here,
         * as `invalid_target`, before the person is shown anything.
         */
        try {
            $resource = $pushed !== null
                ? ResourceParameter::fromValue($pushed['resource'] ?? null)
                : ResourceParameter::fromRequest($request);

            app(AudienceResolver::class)->resolve(
                $client,
                array_values(array_filter($requestedScopes, $client->allows(...))),
                $resource,
            );
        } catch (InvalidAudience $refused) {
            return $this->redirectError($redirectUri, $refused->error, $state, $refused->getMessage());
        }

        $nonceParam = $from('nonce');

        /*
         * RFC 9470 / OIDC Core §3.1.2.1: `max_age` and `acr_values`, read by the framework
         * — the same reader the PAR endpoint refuses a malformed value with, and the same
         * requirement a resource server evaluates the resulting token against. From the
         * pushed request alone when there is one (RFC 9126 §4: the pushed parameters ARE
         * the request).
         *
         * A malformed `max_age` is REFUSED, where it used to be ignored: it is a demand
         * about how recent the sign-in must be, and dropping it would hand the client a
         * code minted from a sign-in it explicitly asked not to accept.
         */
        try {
            $requirement = AuthenticationRequirement::fromAuthorizationRequest($pushed ?? $request);
        } catch (InvalidAuthenticationRequirement $invalid) {
            return $this->redirectError($redirectUri, $invalid->error, $state, $invalid->getMessage());
        }

        /*
         * OIDC `prompt`, parsed once into the values this endpoint honours. See
         * {@see AuthorizationPrompt} for why an unknown value is ignored rather than refused.
         */
        $prompts = AuthorizationPrompt::parse($from('prompt'));

        /*
         * THE ORGANIZATION PARAMETERS COME FROM THE PUSHED REQUEST ALONE when there is one.
         * `$from()` falls back to the query for anything the payload lacks, and for these
         * two that fallback would be a hole: a link carrying a pushed request_uri plus
         * `&organization=…` would bind the grant to an organization the client never asked
         * for — one of the person's own, so no membership check would stop it, and the app
         * would receive another team's roles. RFC 9126 §4 says the pushed parameters are the
         * request; here that is enforced where it matters most.
         */
        $organizationParam = $pushed !== null ? ($pushed['organization'] ?? null) : $request->input('organization');
        $hintParam = $pushed !== null ? ($pushed['organization_hint'] ?? null) : $request->input('organization_hint');

        /*
         * PRESENT, not merely non-null. The request pipeline turns `organization=` into null,
         * and a request that SENT the parameter empty is not one that sent none: the app
         * meant to bind to something, and binding it to the session's organization instead
         * would answer a question it did not ask.
         */
        $organizationSent = $pushed !== null
            ? array_key_exists('organization', $pushed)
            : ($request->query->has('organization') || $request->request->has('organization'));

        $refusal = $this->invalidOrganizationRequest($prompts, $organizationSent ? ($organizationParam ?? '') : null, $hintParam);

        if ($refusal !== null) {
            return $this->redirectError($redirectUri, 'invalid_request', $state, $refusal);
        }

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
            resource: $resource,
            maxAge: $requirement->maxAge,
            acrValues: $requirement->acrValues === [] ? null : implode(' ', $requirement->acrValues),
            /*
             * The consumed PAR payload, kept because the request may still need to be
             * RESUMED after sign-in or an account switch. Without it a resume rebuilt a
             * plain query URL, and a FAPI deployment (`require_par`) then refused its own
             * resumed request — every unauthenticated person dead-ended on "this server
             * requires pushed authorization requests".
             */
            pushedPayload: $pushed,
            organizationId: is_string($organizationParam) ? $organizationParam : null,
            organizationHint: is_string($hintParam) && $hintParam !== '' ? $hintParam : null,
            prompts: $prompts,
        );

        /*
         * A SUPPORT SESSION this browser holds for this app. An environment administrator
         * started it from the console and was handed to the app, and this is the app's own
         * sign-in arriving: it is answered with a code minted FOR THE SESSION, bound to the
         * app's PKCE challenge, whose tokens carry `act` and never a refresh token. Ahead of
         * everything about the signed-in person, because the administrator is nobody on
         * this tenant — and never when a login ticket names who just signed in here.
         *
         * AFTER the organization parameters are validated and bound (from the pushed request
         * alone when there is one): the session is one person in one organization, and a
         * request naming another, or asking to create one, is refused to the app rather
         * than answered with the session's or sent to a sign-in page.
         */
        try {
            $supportCode = $from('login_ticket') === null
                ? app(SupportAccess::class)->codeFor($authorization)
                : null;
        } catch (SupportRequestRefused $refused) {
            return $this->redirectError($redirectUri, 'access_denied', $state, $refused->getMessage());
        }

        if ($supportCode !== null) {
            $params = ['code' => $supportCode, 'iss' => app(IssuerResolver::class)->issuer()];

            if ($state !== null) {
                $params['state'] = $state;
            }

            return $this->leave($this->buildRedirect($redirectUri, $params));
        }

        /*
         * OIDC `prompt` handling. `select_account` sends the person to the account chooser;
         * `login` goes straight to add-another-account. Neither signs anyone out — the
         * chosen account becomes active and the request resumes, carrying `reauthed=1` so
         * re-entry does not loop.
         */
        $silent = $authorization->asks(AuthorizationPrompt::None);
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

        /*
         * OIDC PROMPT CREATE (Initiating User Registration): the sign-up form instead of the
         * sign-in form, and back into this request afterwards. Somebody already signed in
         * is not given a second account — the authorization simply continues as them, and
         * an app that wants a fresh sign-in first says `prompt=login` as well.
         */
        if (! $reauthed && ! $me->check() && $authorization->asks(AuthorizationPrompt::Create)) {
            return $this->interrupt($request, $authorization, route('signup'));
        }

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

        /*
         * AT THE PLATFORM ROOT, ONLY THE PEOPLE WHO RUN A WORKSPACE. An MCP client signing a
         * person in there is connecting to the root's `/mcp` — a workspace's team, an
         * operator — and the root is nobody else's sign-in ({@see RootMcpOAuth}). Anyone
         * else is told so here, on the page, rather than shown a consent screen for a
         * connection that would answer every call with nothing.
         */
        if ($this->refusedAtRoot($client, $me)) {
            if ($silent) {
                return $this->redirectError($redirectUri, 'access_denied', $state,
                    'Only a member of a workspace\'s team or an operator can connect an MCP client at the platform root.');
            }

            return $this->failure(__('oauth.failure.no_workspace'));
        }

        if (! $reauthed && $authorization->asks(AuthorizationPrompt::SelectAccount)) {
            return $this->interrupt($request, $authorization, route('accounts'));
        }

        if (! $reauthed && $authorization->asks(AuthorizationPrompt::Login)) {
            return $this->interrupt($request, $authorization, route('accounts.add'));
        }

        /*
         * STEP-UP. `max_age` and `acr_values` are the two controls OIDC gives a relying
         * party to demand a FRESH or a STRONGER authentication before a sensitive operation
         * — a payment, an admin grant. Both were accepted and ignored once: a client calling
         * `login({maxAge: 0})` got a code minted from a day-old session carrying the
         * ORIGINAL auth_time, and one asking for aal2 got a password-only user authorized
         * and an aal1 id_token, with no way to tell either had happened.
         *
         * Assessed by the framework ({@see AuthenticationRequirement::assessSession()}), the
         * rule a resource server applies to the token at the other end, and answered by
         * what fell short: too OLD means sign in again; not STRONG enough means a second
         * factor — the one screen that adds it, when the person has an authenticator, and a
         * fresh sign-in (where a passkey reaches aal2) when they do not.
         *
         * Coming back still unsatisfied means the requirement is genuinely unmeetable here,
         * and the honest answer is an error to the client rather than a token quietly
         * asserting less than was demanded — so `reauthed` is never trusted as satisfaction.
         */
        $assessment = $authorization->authenticationRequirement()->assessSession($me->session());

        if (! $assessment->isSatisfied()) {
            if ($reauthed || $silent) {
                return $this->redirectError($redirectUri, (string) $assessment->authorizationError(), $state, (string) $assessment->errorDescription());
            }

            if (! $assessment->requiresReauthentication() && $assessment->requiresStepUp()) {
                return $this->stepUp($request, $authorization, $me);
            }

            return $this->interrupt($request, $authorization, route('accounts.add'));
        }

        /*
         * WHICH ORGANIZATION. Decided only now, with the person known and every sign-in rule
         * satisfied, because the answer is about THEIR memberships.
         *
         *  - `organization` names one: bind to it if they may use it, otherwise tell the
         *    client `access_denied`. The same answer for an organization that does not
         *    exist, one in another environment, one they left and one that was suspended,
         *    so the error confirms nothing about somebody else's tenant.
         *
         *    `access_denied` under `prompt=none` too, rather than `interaction_required`:
         *    no page this server could show would make this account a member, and an SDK
         *    reading `interaction_required` retries interactively only to be refused again.
         *  - `prompt=create_organization` / `prompt=select_organization` hand over to the
         *    hosted steps, which bind the grant and then come back through proceed().
         *  - otherwise the grant carries the session's organization, as it always has.
         */
        if ($authorization->organizationId !== null) {
            if (app(AuthorizationOrganizations::class)->usableBy($me->id(), $authorization->organizationId) === null) {
                return $this->redirectError($redirectUri, 'access_denied', $state,
                    'The user is not an active member of the requested organization.');
            }
        } elseif ($authorization->asks(AuthorizationPrompt::CreateOrganization)) {
            return redirect()->route('oauth.authorize.organization.create', $pending->put($request, $authorization));
        } elseif ($authorization->asks(AuthorizationPrompt::SelectOrganization)) {
            return redirect()->route('oauth.authorize.organization', $pending->put($request, $authorization));
        }

        return $this->proceed($request, $authorization, $client, $clients, $codes, $pending, $silent, redirectToScreen: false);
    }

    /**
     * `GET /oauth/authorize/{authorization}` — the consent screen for a request that has
     * already been through one of the hosted organization steps.
     *
     * Those steps POST, and answering a POST with the consent page would leave the browser
     * on the step's URL, where a reload re-submits a choice that has already been spent. So
     * they redirect here instead, and the screen is drawn from the request held server-side
     * under this id.
     */
    public function review(Request $request, string $authorization, PendingAuthorizations $pending): Response
    {
        $found = $pending->find($request, $authorization);

        if ($found === null || ! app(CurrentUser::class)->check()) {
            return $this->failure(__('oauth.failure.stale'));
        }

        return $this->consentScreen($authorization, $found);
    }

    /**
     * `GET /oauth/authorize/{authorization}/organization` — THE HOSTED ORGANIZATION PICKER.
     *
     * Lists the organizations the signed-in person may bind this app to here, and nothing
     * else: live organizations in this environment held through an active membership.
     * Choosing one binds THIS grant. It does not move the person's console, and nothing is
     * remembered for the next app — a picker that quietly changed the session would make
     * one app's choice the next app's default.
     */
    public function organization(
        Request $request,
        string $authorization,
        PendingAuthorizations $pending,
        AuthorizationOrganizations $organizations,
    ): Response {
        $found = $pending->find($request, $authorization);
        $me = app(CurrentUser::class);

        if ($found === null || ! $me->check()) {
            return $this->failure(__('oauth.failure.stale'));
        }

        $choices = $organizations->choicesFor($me->id());
        $ids = array_map(static fn (OrganizationChoice $choice): string => $choice->id, $choices);

        /*
         * PRESELECTED: the app's hint when it names one of these, else the organization the
         * person is working in, else the first. A hint naming anything else is ignored
         * without comment — it is a suggestion from the app, and saying "you are not in
         * that one" would confirm to the app which organizations exist.
         */
        $selected = match (true) {
            $found->organizationHint !== null && in_array($found->organizationHint, $ids, true) => $found->organizationHint,
            in_array($me->organizationId(), $ids, true) => $me->organizationId(),
            default => $ids[0] ?? null,
        };

        return $this->page('oauth/organization', __('oauth.organization.title'), [
            'client' => ['name' => $found->clientName, 'owner' => $found->clientOwner],
            'me' => $this->meProps($me),
            'organizations' => array_map(static fn (OrganizationChoice $choice): array => [
                'id' => $choice->id,
                'name' => $choice->name,
                'role' => __('oauth.organization.roles.'.$choice->role->value),
            ], $choices),
            'selected' => $selected,
            'chooseHref' => route('oauth.authorize.organization.choose', $authorization),
            'createHref' => $organizations->creationOffered()
                ? route('oauth.authorize.organization.create', $authorization)
                : null,
            'denyHref' => route('oauth.authorize.deny', $authorization),
        ]);
    }

    public function chooseOrganization(
        ChooseOrganizationRequest $request,
        string $authorization,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
        AuthorizationOrganizations $organizations,
    ): Response|SymfonyResponse {
        $found = $pending->find($request, $authorization);
        $me = app(CurrentUser::class);

        if ($found === null || ! $me->check()) {
            return $this->failure(__('oauth.failure.stale'));
        }

        // The posted id is a CLAIM. Only an organization this person may use right now is
        // accepted — the list on the page was drawn on another request.
        $choice = $organizations->usableBy($me->id(), $request->organizationId());

        if ($choice === null) {
            return back()->withErrors(['organization' => __('oauth.organization.not_member')]);
        }

        return $this->continueBound($request, $authorization, $found->boundTo($choice->id), $clients, $codes, $pending);
    }

    /**
     * `GET /oauth/authorize/{authorization}/organization/new` — THE HOSTED "CREATE AN
     * ORGANIZATION" STEP. A name, and the person becomes its Owner.
     */
    public function createOrganization(
        Request $request,
        string $authorization,
        PendingAuthorizations $pending,
        AuthorizationOrganizations $organizations,
    ): Response {
        $found = $pending->find($request, $authorization);
        $me = app(CurrentUser::class);

        if ($found === null || ! $me->check()) {
            return $this->failure(__('oauth.failure.stale'));
        }

        if (! $organizations->creationOffered()) {
            return $this->failure(OrganizationCreationRefused::notOffered()->getMessage());
        }

        return $this->page('oauth/create-organization', __('oauth.create_organization.title'), [
            'client' => ['name' => $found->clientName, 'owner' => $found->clientOwner],
            'me' => $this->meProps($me),
            'storeHref' => route('oauth.authorize.organization.store', $authorization),
            // Back to the picker when the app asked for one, so "create" is a detour from
            // choosing rather than a dead end; otherwise the only way out is to cancel.
            'pickerHref' => $found->asks(AuthorizationPrompt::SelectOrganization)
                ? route('oauth.authorize.organization', $authorization)
                : null,
            'denyHref' => route('oauth.authorize.deny', $authorization),
        ]);
    }

    public function storeOrganization(
        CreateOrganizationRequest $request,
        string $authorization,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
        AuthorizationOrganizations $organizations,
    ): Response|SymfonyResponse {
        $found = $pending->find($request, $authorization);
        $me = app(CurrentUser::class);

        if ($found === null || ! $me->check()) {
            return $this->failure(__('oauth.failure.stale'));
        }

        try {
            $created = $organizations->create($me->id(), $request->organizationName());
        } catch (OrganizationCreationRefused $refused) {
            return back()->withInput()->withErrors(['name' => $refused->getMessage()]);
        }

        return $this->continueBound($request, $authorization, $found->boundTo($created->id), $clients, $codes, $pending);
    }

    /**
     * Spend the step's pending entry and carry on with the bound request: straight to the
     * app for a first-party client that skips consent, otherwise to the consent screen.
     */
    private function continueBound(
        Request $request,
        string $spent,
        PendingAuthorization $bound,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
    ): Response|SymfonyResponse {
        // Spent either way: a second submit from a stale tab must not bind a second grant.
        $pending->forget($request, $spent);

        $authorizing = $this->authorizationClient($bound->clientId);

        if ($authorizing === null) {
            return $this->failure(__('oauth.failure.stale'));
        }

        return $this->proceed($request, $bound, $authorizing->client, $clients, $codes, $pending, silent: false, redirectToScreen: true);
    }

    /**
     * Consent, or straight to the app — the end of every path through the endpoint.
     *
     * FIRST-PARTY CONSENT-SKIP: an organization's own trusted app — or a platform-owned
     * first-party client — authorizes without a prompt. STRICTLY organization-scoped: a
     * first-party client owned by ANOTHER organization still prompts, so it can never
     * silently mint a code for a different tenant's user. The issuing path re-asserts
     * every invariant, so this skips the screen and never the checks.
     *
     * "Another organization" is measured against the organization the grant is FOR, not the
     * one the session happens to be in: an app bound to Globex is not Acme's own app acting
     * inside Acme, whatever the console was last looking at. And `prompt=consent` always
     * shows the screen — that is the whole of what the value asks for.
     */
    private function proceed(
        Request $request,
        PendingAuthorization $authorization,
        Client $client,
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        PendingAuthorizations $pending,
        bool $silent,
        bool $redirectToScreen,
    ): Response|SymfonyResponse {
        $organizationId = $authorization->organizationId ?? app(CurrentUser::class)->organizationId();

        // Never for a client that registered ITSELF — RFC 7591 or a metadata document. It is
        // a stranger by definition, whatever a flag on its row says.
        $skipConsent = $client->first_party === true
            && ! $client->isDynamicallyRegistered()
            && ($client->organization_id === null || $client->organization_id === $organizationId)
            && ! $authorization->asks(AuthorizationPrompt::Consent);

        if ($silent && ! $skipConsent) {
            /*
             * Through redirectError() so this branch carries the RFC 9207 `iss` too —
             * building the redirect directly here was the one error path that omitted it,
             * and a mix-up-hardened client checks it on errors as well.
             */
            return $this->redirectError($authorization->redirectUri, 'interaction_required', $authorization->state,
                'User interaction is required to authorize this request.');
        }

        if ($skipConsent) {
            return $this->issue($request, $authorization, $clients, $codes);
        }

        $id = $pending->put($request, $authorization);

        return $redirectToScreen
            ? redirect()->route('oauth.authorize.review', $id)
            : $this->consentScreen($id, $authorization);
    }

    private function consentScreen(string $id, PendingAuthorization $authorization): Response
    {
        $me = app(CurrentUser::class);
        $authorizing = $this->authorizationClient($authorization->clientId);

        return $this->page('oauth/consent', __('oauth.consent.title'), [
            'client' => [
                'name' => $authorization->clientName,
                'owner' => $authorization->clientOwner,
                /*
                 * A CLIENT THAT REGISTERED ITSELF says so. Its name, its logo and its link
                 * are whatever whoever registered it typed, and nobody here reviewed it — so
                 * the screen says that plainly instead of "registered by" an owner it does
                 * not have.
                 */
                'selfRegistered' => $authorizing?->consentRequired() ?? false,
                /*
                 * …and one described by a metadata document leads with the one thing about
                 * it that is VERIFIED: the host that published the document. Its
                 * `client_uri` is https-only, and shown as the publisher's.
                 *
                 * ITS `logo_uri` IS NOT DRAWN. It is an image on somebody else's host, and an
                 * `<img>` of it would report every person who reached this screen — address,
                 * browser, the moment they were asked — to whoever published the document,
                 * whether or not they went on to allow anything. The hosted pages draw only
                 * images this application serves (`img-src 'self'`); the app is named, and
                 * its verified host leads, which is what a person can actually check.
                 */
                'documentHost' => $authorizing?->documentHost,
                'clientUri' => $authorizing?->clientUri,
            ],
            'me' => $this->meProps($me),
            /*
             * WHICH ORGANIZATION the app will see this person in. Their tokens carry its
             * roles, so somebody in three teams is agreeing to something different in each —
             * and the screen is the one place they can notice the app picked the wrong one.
             */
            'organization' => $this->organizationName($authorization, $me),
            /*
             * FROM THE CATALOG, not a second copy of it. The old map held four strings and
             * fell back to the raw scope key for everything else — so a person deciding
             * whether to allow an app was shown the literal word "groups", with nothing to
             * say what it meant, on the most end-user-facing page in the product.
             */
            /*
             * FROM THE CATALOG, not a second copy of it — and the management plane's scopes
             * from the action registry, with the ones a critical action needs flagged
             * ({@see ConsentScopes}). What is listed is what the token will carry.
             */
            'scopes' => app(ConsentScopes::class)->rows($this->grantedScopes($authorizing, $authorization)),
            'redirectHost' => parse_url($authorization->redirectUri, PHP_URL_HOST),
            'approveHref' => route('oauth.authorize.approve', $id),
            'denyHref' => route('oauth.authorize.deny', $id),
        ]);
    }

    /** @return array{name: string, email: string|null, initial: string} */
    private function meProps(CurrentUser $me): array
    {
        return [
            'name' => $me->name(),
            'email' => $me->email(),
            'initial' => mb_strtoupper(mb_substr($me->name(), 0, 1)),
        ];
    }

    private function organizationName(PendingAuthorization $authorization, CurrentUser $me): ?string
    {
        if ($authorization->organizationId === null) {
            return $me->organization()?->name;
        }

        return app(AuthorizationOrganizations::class)->usableBy($me->id(), $authorization->organizationId)?->name;
    }

    /**
     * A reason the organization parameters cannot be answered as sent, or null.
     *
     * Each of these is a request with two meanings, and guessing which one the app meant is
     * how it ends up bound to an organization it did not ask for. id-js refuses the same
     * combinations before the redirect; this is the same rule for every other client.
     *
     * @param  list<AuthorizationPrompt>  $prompts
     */
    private function invalidOrganizationRequest(array $prompts, mixed $organization, mixed $hint): ?string
    {
        $asks = static fn (AuthorizationPrompt $prompt): bool => in_array($prompt, $prompts, true);

        // OIDC Core §3.1.2.1: `none` with any other value is an error.
        if ($asks(AuthorizationPrompt::None) && count($prompts) > 1) {
            return 'prompt=none cannot be combined with another prompt value.';
        }

        if ($organization !== null && (! is_string($organization) || trim($organization) === '')) {
            return 'The organization parameter is empty. Omit it to authorize without binding to an organization.';
        }

        if ($hint !== null && ! is_string($hint)) {
            return 'The organization_hint parameter must be a single organization id.';
        }

        if ($organization !== null && $asks(AuthorizationPrompt::SelectOrganization)) {
            return 'organization binds the request to one organization, so prompt=select_organization has nothing to choose. Send organization_hint to preselect one in the picker instead.';
        }

        if ($organization !== null && $asks(AuthorizationPrompt::CreateOrganization)) {
            return 'organization binds the request to an existing organization and prompt=create_organization creates a new one. Send one or the other.';
        }

        if ($asks(AuthorizationPrompt::Create) && $organization !== null) {
            return 'prompt=create signs up a new account, which is not a member of any organization yet. Omit organization.';
        }

        if ($asks(AuthorizationPrompt::Create) && $asks(AuthorizationPrompt::CreateOrganization)) {
            return 'prompt=create already asks the new account for its organization. Omit create_organization.';
        }

        $signup = app(SignupPolicy::class);

        // OpenID Connect Prompt Create §4: a prompt value the server does not offer is
        // `invalid_request`, and discovery does not list `create` where signup is closed.
        if ($asks(AuthorizationPrompt::Create) && ! $signup->isOpen()) {
            return 'Self-service sign-up is not available here, so prompt=create cannot be honoured.';
        }

        if ($asks(AuthorizationPrompt::CreateOrganization) && ! $signup->allowsCreatingOrganizations()) {
            return 'Creating an organization is not available here, so prompt=create_organization cannot be honoured.';
        }

        return null;
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
            return $this->failure(__('oauth.failure.stale'));
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
            return $this->failure(__('oauth.failure.stale'));
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
            return $this->failure(__('oauth.failure.stale'));
        }

        /*
         * And never past a hold that landed after the page was drawn. A consent page opened
         * before an administrator mandated MFA, or before a password expired, must not mint
         * a code afterwards: somebody keeps a tab open, the policy changes, they click Allow.
         */
        if ($this->unsatisfiedAuthPolicy($me->subject()?->id) !== null) {
            return $this->failure(__('oauth.failure.account_attention'));
        }

        // Defence in depth: the redirect_uri must still be registered to the client, and
        // PKCE must still be S256.
        $authorizing = $this->authorizationClient($authorization->clientId);

        if ($authorizing === null
            || ! $this->redirectAllowed($authorizing, $authorization->redirectUri)
            || $authorization->codeChallenge === ''
            || $authorization->codeChallengeMethod !== 'S256') {
            return $this->failure(__('oauth.failure.stale'));
        }

        // …and at the platform root, still somebody who runs a workspace: a membership ended
        // or an operator suspended while the screen sat open mints nothing.
        if ($this->refusedAtRoot($authorizing->client, $me)) {
            return $this->failure(__('oauth.failure.no_workspace'));
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

        if (! $authorization->authenticationRequirement()->assessSession($session)->isSatisfied()) {
            return $this->failure(__('oauth.failure.step_up'));
        }

        /*
         * THE BOUND ORGANIZATION, ASKED AGAIN. It was checked when the request arrived or
         * when the person chose it — on another request. A membership removed, suspended or
         * an organization closed while the consent screen sat open must not come back as a
         * code whose tokens assert a role the person no longer holds. Answered to the CLIENT
         * as `access_denied`, the same refusal the request would get if it arrived now.
         */
        if ($authorization->organizationId !== null
            && app(AuthorizationOrganizations::class)->usableBy($me->id(), $authorization->organizationId) === null) {
            return $this->redirectError($authorization->redirectUri, 'access_denied', $authorization->state,
                'The user is not an active member of the requested organization.');
        }

        $code = $codes->issue(
            $authorization->clientId,
            $me->id(),
            /*
             * The organization the grant was bound to, and only when nothing bound one the
             * session's — which is what every authorization carried before an app could
             * choose. The code carries it into the access token, the ID token, UserInfo and
             * every refresh, so this one value is the app's `org` from here on.
             */
            $authorization->organizationId ?? $me->organizationId(),
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
            /*
             * THE SESSION THE PERSON APPROVED FROM (OIDC Back-Channel Logout 1.0). The ID
             * Token carries `sid` derived from it, and the session is recorded against this
             * client — so ending it (sign-out, an administrator's revoke, deactivation)
             * tells this application to end its own session too. Without it the app is
             * reachable only by `sub`, and ending one session cannot name it.
             */
            sessionId: $session?->id,
        );

        // An MCP client allowed at the platform root is recorded in the person's workspace
        // trail, where the team sees it — the root's one consent that matters to a team.
        $root = app(RootMcpOAuth::class);

        if ($root->governs($authorizing->client)) {
            $root->recordConsent($authorizing->client, $me->id(), $authorization->organizationId ?? $me->organizationId(), $authorization->scopes, $request);
        }

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
     * Whether the platform root refuses this person this client: an MCP client that
     * registered itself, at the root, for somebody on no workspace's team who is no operator.
     */
    private function refusedAtRoot(Client $client, CurrentUser $me): bool
    {
        $root = app(RootMcpOAuth::class);

        return $root->governs($client) && ! $root->admits($me->id());
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
            $client = $this->authorizationClient($authorization->clientId)?->client;

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
            /*
             * The organization request and the prompts, so a person who had to sign in first
             * still reaches the picker the app asked for and a grant bound where the app
             * said. `login` and `select_account` are carried too and cannot loop: both are
             * skipped once `reauthed` is set.
             */
            'organization' => $authorization->organizationId,
            'organization_hint' => $authorization->organizationHint,
            'prompt' => implode(' ', array_map(static fn (AuthorizationPrompt $prompt): string => $prompt->value, $authorization->prompts)),
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
     * Send a signed-in person to add a second factor, and come back.
     *
     * With an authenticator — or a phone number the environment accepts — enrolled, that is
     * the second-factor screen itself: the person
     * is held for it exactly as a password sign-in holds them ({@see PlatformAuth::holdForMfa()}),
     * which grants nothing on its own — the code still has to be right — and completing
     * it starts a session whose `amr` carries the second factor. Without one there is no
     * factor to ask for here, and a fresh sign-in is the way to reach aal2 (a passkey
     * does); if that comes back short too, the resumed request answers the client
     * `unmet_authentication_requirements`.
     */
    private function stepUp(Request $request, PendingAuthorization $authorization, CurrentUser $me): RedirectResponse
    {
        $subjectId = $me->subject()?->id;

        if ($subjectId !== null && app(PlatformAuth::class)->hasSecondFactor($subjectId)) {
            app(PlatformAuth::class)->holdForMfa($request, $subjectId);

            return $this->interrupt($request, $authorization, route('mfa'));
        }

        return $this->interrupt($request, $authorization, route('accounts.add'));
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
        return $this->page('oauth/consent', __('oauth.failure.title'), ['error' => $message]);
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
     * The scopes the token will carry, which is what the person is agreeing to: the
     * requested ones the client may hold, narrowed by the audience resolver the token
     * endpoint asks — so the screen never lists a scope redemption would drop.
     *
     * @return list<string>
     */
    private function grantedScopes(?AuthorizationClient $authorizing, PendingAuthorization $authorization): array
    {
        // A client that registered no scopes is not constrained at /authorize (see show()),
        // and is shown what it asked for, as it always was.
        if ($authorizing === null || $authorizing->client->scopes === []) {
            return $authorization->scopes;
        }

        $client = $authorizing->client;

        try {
            return app(AudienceResolver::class)->resolve(
                $client,
                array_values(array_filter($authorization->scopes, $client->allows(...))),
                $authorization->resource,
            )->scopes;
        } catch (InvalidAudience) {
            // Refused at /authorize before a pending authorization exists; reaching here
            // means the client's registration changed while the page was open, and the
            // approval re-asserts everything anyway. Show what was asked.
            return $authorization->scopes;
        }
    }

    /**
     * The client an authorization names, registered or described by a metadata document —
     * or null when it no longer resolves, which every caller treats as a stale request.
     */
    private function authorizationClient(string $clientId): ?AuthorizationClient
    {
        try {
            return app(AuthorizationClients::class)->resolve($clientId);
        } catch (InvalidClientMetadataDocument) {
            return null;
        }
    }

    /**
     * Whether $redirectUri is one this client may be sent back to.
     *
     * A metadata document client by the framework's rule — an EXACT match against the
     * document's `redirect_uris`, since the document is the publisher's whole claim. A
     * registered client by {@see redirectUriRegistered()}, this endpoint's own rule, which
     * lets only a 127.0.0.1 / [::1] port float.
     *
     * `array_values()`: `redirect_uris` is a JSON cast, so a row written as a JSON object
     * rather than an array rehydrates with string keys. `redirectUriRegistered()` asks for
     * a list, and the re-key is what makes that true — not decoration.
     */
    private function redirectAllowed(AuthorizationClient $authorizing, string $redirectUri): bool
    {
        if ($authorizing->isMetadataDocumentClient()) {
            return $authorizing->allowsRedirectUri($redirectUri);
        }

        return $this->redirectUriRegistered($redirectUri, array_values($authorizing->client->redirect_uris));
    }

    private function owner(Client $client): string
    {
        // Nobody registered it but itself; the screen says so in its own words.
        if ($client->isDynamicallyRegistered()) {
            return __('oauth.consent.self_registered_owner');
        }

        if ($client->organization_id !== null) {
            return app(Organizations::class)->find($client->organization_id)->name
                ?? __('oauth.consent.unknown_owner');
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
