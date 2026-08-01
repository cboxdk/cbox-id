<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\OrganizationAccess;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Authorize'])] class extends Component
{
    /**
     * Slack on the `max_age` comparison, in seconds.
     *
     * Covers the one redirect between a re-authentication being recorded and the resumed
     * authorization request reading it, plus modest skew between the database clock that
     * stamps `sessions.created_at` and the PHP clock that compares it. See
     * {@see unmetAuthenticationRequirement()} for why a strict comparison made
     * `max_age=0` unsatisfiable.
     */
    private const MAX_AGE_SKEW_SECONDS = 60;

    // Validated request parameters. Locked: mount() validates these once against
    // the registered client (redirect_uri exact-match, PKCE, response_type). Livewire
    // lets the browser mutate public properties between requests, so without #[Locked]
    // an attacker could swap in an unregistered redirect_uri AFTER validation and
    // still have approve() mint a code — an open-redirect / code-exfiltration hole.
    #[Locked]
    public string $clientId = '';

    #[Locked]
    public string $clientName = '';

    /**
     * WHO registered this client. The name above is attacker-chosen free text — any org
     * admin in the environment may register an app called "Cbox ID Account Sync" and
     * point another org's users at it — so the screen has to show provenance the
     * registrant does not control alongside the name they do.
     *
     * Attribution, NOT an access check: cross-org consent is deliberate (a genuine
     * multi-tenant app is registered by one org and used by many), so the answer is to
     * tell the user who they are trusting, not to refuse.
     */
    #[Locked]
    public string $clientOwner = '';

    #[Locked]
    public string $redirectUri = '';

    /**
     * NOT `list<string>`, however much it looks like one. mount() assigns a list, but
     * Livewire re-hydrates public properties from the request payload on every
     * subsequent call, so the type that reaches approve() is whatever survived that
     * round trip. Annotating the aspiration instead of the fact is what let the
     * `array_values()` at the issue() call read as decoration — it is the thing that
     * makes the list guarantee true, and PHPStan can only see that if this is honest.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $scopes = [];

    #[Locked]
    public ?string $state = null;

    #[Locked]
    public string $codeChallenge = '';

    #[Locked]
    public string $codeChallengeMethod = 'S256';

    #[Locked]
    public ?string $nonce = null;

    // Set when the request is malformed or the client/redirect_uri cannot be trusted.
    #[Locked]
    public ?string $error = null;

    /**
     * Query params arrive here from the route (real request) or from Volt::test's
     * second argument (mount params). Anything not supplied falls back to the
     * current request's query string.
     */
    /**
     * The consumed PAR payload, kept so a resumed request can be re-pushed.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $pushedPayload = null;

    /**
     * OIDC Core §3.1.2.1 `max_age`: the greatest age, in seconds, of an authentication
     * the relying party will still accept. Kept so the resumed request re-states it and
     * so approve() can re-assert it at issue time.
     */
    #[Locked]
    public ?int $maxAge = null;

    /** The raw OIDC `acr_values` request, kept for the same two reasons. */
    #[Locked]
    public ?string $acrValues = null;

    public function mount(
        ClientRegistry $clients,
        AuthorizationCodes $codes,
        ?string $client_id = null,
        ?string $redirect_uri = null,
        ?string $response_type = null,
        ?string $scope = null,
        ?string $state = null,
        ?string $code_challenge = null,
        ?string $code_challenge_method = null,
        ?string $nonce = null,
        ?string $prompt = null,
        ?string $reauthed = null,
        ?string $request_uri = null,
        ?string $max_age = null,
        ?string $acr_values = null,
    ): void {
        $request = request();

        // RFC 9126: if the client pushed its request, take the parameters from the
        // single-use request_uri rather than the (untrusted, tamperable) query.
        $pushed = [];
        $requestUri = $request_uri ?? $request->query('request_uri');
        $requestClientId = $client_id ?? $request->query('client_id');
        if (is_string($requestUri) && $requestUri !== '' && is_string($requestClientId)) {
            $pushed = app(PushedAuthorizationRequests::class)->consume($requestClientId, $requestUri) ?? null;
            // Keep the payload: mount() consumes the single-use request_uri, but the
            // request may still need to be RESUMED after sign-in or an account switch.
            // Without this, resumeUrl() rebuilt a plain query URL and a FAPI deployment
            // (require_par) then refused its own resumed request — every unauthenticated
            // user dead-ended on "this server requires pushed authorization requests".
            $this->pushedPayload = is_array($pushed) ? $pushed : null;
            if ($pushed === null) {
                $this->error = 'This authorization request has expired or was already used. Please start again.';

                return;
            }
        } elseif (config('cbox-id.oauth.require_par') === true) {
            // FAPI baseline: every authorization request must be pushed (RFC 9126),
            // so raw query-string requests are refused.
            $this->error = 'This server requires pushed authorization requests. Send the request to /oauth/par first.';

            return;
        }

        $from = fn (string $key, ?string $arg) => $pushed[$key] ?? $arg ?? $request->query($key);

        $clientId = $from('client_id', $client_id);
        $redirectUri = $from('redirect_uri', $redirect_uri);
        $responseType = $from('response_type', $response_type);
        $scopeParam = $from('scope', $scope);
        // Narrowed to ?string HERE rather than at each use. `state` is echoed back to the
        // client on every error branch below, and $from() returns whatever the PAR payload
        // or the query string held — a crafted `?state[]=x` makes it an array. Normalising
        // once means the RFC 6749 §4.1.2.1 echo cannot be handed a non-string.
        $stateRaw = $from('state', $state);
        $stateParam = is_string($stateRaw) ? $stateRaw : null;
        $codeChallenge = $from('code_challenge', $code_challenge);
        $codeChallengeMethod = $from('code_challenge_method', $code_challenge_method) ?? 'S256';
        $nonceParam = $from('nonce', $nonce);

        // ORDER MATTERS. RFC 6749 §4.1.2.1 splits errors in two: once the client and
        // its redirect_uri are known-good, an error must be RETURNED TO THE CLIENT as a
        // redirect carrying `error` and `state`; only an unknown client or an
        // unregistered redirect_uri may be shown as a page (redirecting there would be
        // an open redirect).
        //
        // response_type used to be checked FIRST, so the code could not redirect even
        // when it should: an RP configured for hybrid flow, or one omitting PKCE, got a
        // human-readable HTML page, its callback never fired, and its SDK hung until
        // timeout with no error code. That is also an outright fail in the OpenID
        // basic-certification profile.

        // The client must exist.
        $client = is_string($clientId) && $clientId !== '' ? $clients->byClientId($clientId) : null;

        if (! $client instanceof Client) {
            $this->error = 'Unknown client. This application is not registered with Cbox ID.';

            return;
        }

        // The redirect_uri must exactly match one the client registered. Never
        // redirect to a URI we have not verified.
        // array_values(): `redirect_uris` is a JSON cast, so a row written as a JSON
        // object rather than an array rehydrates with string keys. redirectUriRegistered()
        // asks for a list and the re-key is what makes that true, not decoration.
        if (! is_string($redirectUri) || ! $this->redirectUriRegistered($redirectUri, array_values($client->redirect_uris))) {
            $this->error = 'The redirect URI does not match any registered for this application.';

            return;
        }

        // From here the redirect_uri is verified, so every remaining error goes BACK to
        // the client in the RFC-defined shape rather than being rendered.
        if ($responseType !== 'code') {
            $this->redirectError($redirectUri, 'unsupported_response_type', $stateParam,
                'Only the authorization code flow is supported.');

            return;
        }

        // PKCE is mandatory, and we only accept S256.
        if (! is_string($codeChallenge) || $codeChallenge === '') {
            $this->redirectError($redirectUri, 'invalid_request', $stateParam,
                'A PKCE code_challenge is required.');

            return;
        }

        if ($codeChallengeMethod !== 'S256') {
            $this->redirectError($redirectUri, 'invalid_request', $stateParam,
                'Only the S256 code_challenge_method is supported.');

            return;
        }

        // RFC 6749 §3.3 / §4.1.2.1: a scope the client is not registered for is
        // `invalid_scope`, and the error belongs at /authorize where the developer can
        // see it. Letting it through meant the request succeeded, the issuer quietly
        // filtered the scope down at mint time, and the client's next API call 403'd
        // with nothing anywhere to explain why. Refusing here is also what makes the
        // `scope` echo on the token response a diagnostic rather than the only signal.
        //
        // A client that registered NO scopes has declared no surface at all, so there is
        // nothing to check it against — the issuer already grants it nothing.
        $requestedScopes = $this->parseScopes(is_string($scopeParam) ? $scopeParam : '');
        $unregistered = $client->scopes === []
            ? []
            : array_values(array_filter($requestedScopes, static fn (string $s): bool => ! $client->allows($s)));

        if ($unregistered !== []) {
            $this->redirectError($redirectUri, 'invalid_scope', $stateParam,
                'This application is not registered for the requested scope(s): '.implode(' ', $unregistered).'.');

            return;
        }

        $platformName = config('app.name');

        $this->clientId = $client->client_id;
        $this->clientName = $client->name;
        $this->clientOwner = $client->organization_id !== null
            ? (app(Organizations::class)->find($client->organization_id)->name ?? 'an organization that no longer exists')
            : (is_string($platformName) && $platformName !== '' ? $platformName : 'Cbox ID');
        $this->redirectUri = $redirectUri;
        $this->scopes = $requestedScopes;
        $this->state = $stateParam;
        $this->codeChallenge = $codeChallenge;
        $this->codeChallengeMethod = $codeChallengeMethod;
        $this->nonce = is_string($nonceParam) ? $nonceParam : null;
        // Set BEFORE the sign-in / re-auth redirects below, so resumeUrl() re-states
        // them: a step-up requirement that evaporated on the way back through the
        // login screen would be no requirement at all.
        $this->maxAge = self::parseMaxAge($from('max_age', $max_age));
        $acrParam = $from('acr_values', $acr_values);
        $this->acrValues = is_string($acrParam) && trim($acrParam) !== '' ? trim($acrParam) : null;

        // OIDC `prompt` handling. `select_account` sends the user to the account
        // chooser (switch among the accounts signed in on this browser, or add one);
        // `login` goes straight to add-another-account. Neither logs anyone out — the
        // chosen/added account becomes active and the request resumes. The resumed
        // request carries reauthed=1 so re-entry doesn't loop, and it's a plain query
        // URL, so it works even when the original request was pushed (PAR), whose
        // single-use request_uri has already been consumed above.
        $promptParam = $from('prompt', $prompt);
        $prompts = is_string($promptParam) ? array_values(array_filter(explode(' ', $promptParam))) : [];
        $isReauthed = in_array($from('reauthed', $reauthed), ['1', 'true'], true);

        // This route is deliberately NOT behind `platform.auth`, so handle the
        // unauthenticated case here — after the client and redirect_uri are verified, so
        // the answer can go back to the client.
        //
        // prompt=none is the SILENT-RENEW path: an SPA loads it in a hidden iframe and
        // waits for a postMessage from the callback. Redirecting to /login framed the
        // sign-in page (or X-Frame-Options blocked it), the promise never resolved, and
        // the SPA logged the user out on every token refresh. OIDC Core §3.1.2.6 wants
        // error=login_required returned to the redirect_uri instead.
        if (! app(CurrentUser::class)->check()) {
            if (in_array('none', $prompts, true)) {
                $this->redirectError($redirectUri, 'login_required', $stateParam,
                    'The user is not signed in and prompt=none forbids interaction.');

                return;
            }

            session()->put('url.intended', $this->resumeUrl());
            $this->redirect(route('login'), navigate: false);

            return;
        }

        if (! $isReauthed && in_array('select_account', $prompts, true)) {
            session()->put('url.intended', $this->resumeUrl());
            $this->redirect(route('accounts'));

            return;
        }

        if (! $isReauthed && in_array('login', $prompts, true)) {
            session()->put('url.intended', $this->resumeUrl());
            $this->redirect(route('accounts.add'));

            return;
        }

        // STEP-UP. `max_age` and `acr_values` are the two controls OIDC gives a relying
        // party to demand a FRESH or a STRONGER authentication before a sensitive
        // operation — a payment, an admin grant. Both were accepted and ignored: a
        // client calling login({maxAge: 0}) got a code minted from a day-old session
        // carrying the ORIGINAL auth_time, and one asking for aal2 got a password-only
        // user authorized and an aal1 id_token, with no way to tell either had happened.
        //
        // The remedy reuses the machinery prompt=login already has: send the user to
        // add-another-account, resume with reauthed=1. What it must NOT do is trust
        // that flag — coming back still unsatisfied means the requirement is genuinely
        // unmeetable here, and the honest answer is an error to the client rather than
        // a token quietly asserting less than was demanded.
        $unmet = $this->unmetAuthenticationRequirement(app(CurrentUser::class)->session());

        if ($unmet !== null) {
            // prompt=none forbids interaction, so a step-up cannot even be attempted.
            if ($isReauthed || in_array('none', $prompts, true)) {
                $this->redirectError($redirectUri, $unmet[0], $stateParam, $unmet[1]);

                return;
            }

            session()->put('url.intended', $this->resumeUrl());
            $this->redirect(route('accounts.add'));

            return;
        }

        // First-party consent-skip: an org's own trusted app — or a platform-owned
        // first-party client — authorizes without a prompt. STRICTLY org-scoped: a
        // first-party client owned by ANOTHER org still prompts, so it can never
        // silently mint a code for a different tenant's user. approve() re-asserts
        // every invariant (redirect_uri, PKCE/S256, org-not-suspended) before issuing,
        // so this skips the screen, never the checks.
        $userOrgId = app(CurrentUser::class)->organizationId();
        $skipConsent = $client->first_party === true
            && ($client->organization_id === null || $client->organization_id === $userOrgId);

        // prompt=none: no UI is permitted. If we could authorize silently (a trusted
        // first-party client), do so; otherwise return the OIDC error to the client
        // rather than showing the consent screen.
        if (in_array('none', $prompts, true) && ! $skipConsent) {
            // Through redirectError() so this branch carries the RFC 9207 `iss` too —
            // building the redirect directly here was the one error path that omitted
            // it, and a mix-up-hardened client checks it on errors as well.
            $this->redirectError($this->redirectUri, 'interaction_required', $this->state,
                'User interaction is required to authorize this request.');

            return;
        }

        if ($skipConsent) {
            $this->approve($codes, $clients);
        }
    }

    /**
     * Rebuild this authorization request as a plain query URL to resume after a
     * re-authentication prompt — without `prompt` and with a loop guard.
     */
    private function resumeUrl(): string
    {
        // Re-push the original payload under a FRESH single-use request_uri, so the
        // resumed request is a genuine PAR request rather than a query-string one that
        // require_par must refuse. Re-pushing (not reusing) keeps the single-use property
        // intact — and marking the resume as "already pushed" via a flag would let anyone
        // bypass PAR by adding it to a URL.
        if ($this->pushedPayload !== null && $this->clientId !== '') {
            $client = app(ClientRegistry::class)->byClientId($this->clientId);

            if ($client !== null) {
                $repushed = app(PushedAuthorizationRequests::class)->push($client, $this->pushedPayload);

                return route('oauth.authorize', [
                    'client_id' => $this->clientId,
                    'request_uri' => $repushed['request_uri'],
                    'reauthed' => '1',
                ]);
            }
        }

        return route('oauth.authorize', array_filter([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'nonce' => $this->nonce,
            // Re-stated so the step-up requirement survives the round trip. `max_age=0`
            // is the whole point of the parameter ("re-authenticate now"), so it is
            // carried as a string and never filtered out as falsy.
            'max_age' => $this->maxAge !== null ? (string) $this->maxAge : null,
            'acr_values' => $this->acrValues,
            'reauthed' => '1',
        ], static fn (?string $v): bool => $v !== null && $v !== ''));
    }

    /**
     * OIDC Core §3.1.2.1 `max_age`: a non-negative number of seconds. A malformed value
     * is ignored rather than refused — it is a request, not a credential.
     */
    private static function parseMaxAge(mixed $value): ?int
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
     * The OIDC error to return when this session cannot satisfy the request's
     * authentication requirements, or null when it can.
     *
     * @return array{0: string, 1: string}|null [error code, description]
     */
    private function unmetAuthenticationRequirement(?Session $session): ?array
    {
        // `max_age`: compare the session's own AGE against the ceiling the client set.
        // A session we cannot date is treated as too old — fail closed.
        //
        // The comparison is `age > maxAge`, not `authTime < now - maxAge`, and it carries
        // a small skew allowance. With `max_age=0` the strict form reduces to
        // "authenticated strictly before this instant", which is true of EVERY session
        // that exists — including the one the user just created — so the round trip
        // (redirect → sign in → resume) came back still unsatisfied and the request died
        // with `login_required`. `login({maxAge: 0})`, the case the parameter exists for,
        // could therefore never succeed.
        //
        // The allowance only has to cover the gap between a session row being written and
        // the resumed /authorize evaluating it: PlatformAuth::establish() starts the
        // session AFTER the last factor is verified, so that gap is one redirect, not the
        // time the user spent typing an OTP. A minute is generous for it and still means
        // `max_age=0` demands an authentication from the last minute.
        if ($this->maxAge !== null) {
            $authTime = $session?->created_at?->getTimestamp();

            // now(), not time(): one clock for the whole application, and the only one a
            // test can move — which is what makes the arithmetic here provable rather
            // than accidentally true because two statements ran in the same second.
            if (! is_int($authTime) || (now()->getTimestamp() - $authTime) > ($this->maxAge + self::MAX_AGE_SKEW_SECONDS)) {
                return ['login_required', 'The existing authentication is older than the requested max_age.'];
            }
        }

        // `acr_values`: the strongest class named that this server asserts. Anything it
        // does not assert is ignored (the parameter is an ordered list of ACCEPTABLE
        // classes, so a client may legitimately name another IdP's).
        $required = AuthenticationContextClass::fromRequest($this->acrValues);

        if ($required !== null && ! $required->isSatisfiedBy($session !== null ? array_values($session->amr) : [])) {
            // RFC 9470 §5 (and OIDCUAR, which it cites): when it is the AUTHORIZATION
            // request that cannot be satisfied, the error is
            // `unmet_authentication_requirements`. `insufficient_user_authentication` is
            // the RFC 9470 §3 `WWW-Authenticate` challenge a PROTECTED RESOURCE returns —
            // IANA registers it with "Usage Location: resource access error response", so
            // an RP implementing the OIDF step-up pattern branches on the former and falls
            // through to a generic error page on the latter.
            return ['unmet_authentication_requirements', 'The requested authentication context ('.$required->value.') was not met by this session.'];
        }

        return null;
    }

    public function approve(AuthorizationCodes $codes, ClientRegistry $clients): void
    {
        if ($this->error !== null) {
            return;
        }

        // Defense in depth: re-assert the critical invariants at issue time rather
        // than trusting that mount() still holds. Even with #[Locked], never mint a
        // code unless the redirect_uri is still registered to the client and PKCE
        // (S256) is present.
        $client = $clients->byClientId($this->clientId);

        if (! $client instanceof Client
            || ! $this->redirectUriRegistered($this->redirectUri, array_values($client->redirect_uris))
            || $this->codeChallenge === ''
            || $this->codeChallengeMethod !== 'S256') {
            $this->error = 'This authorization request can no longer be completed. Please start again.';

            return;
        }

        $me = app(CurrentUser::class);

        // An organization that is no longer live — suspended OR deleted — cannot
        // authorize applications or mint tokens. {@see OrganizationAccess} is the one
        // place that decides which statuses those are.
        $refusal = OrganizationAccess::refusalPhrase($me->organization()?->status);
        if ($refusal !== null) {
            $this->error = 'This organization has been '.$refusal.' and cannot authorize applications.';

            return;
        }

        $session = $me->session();

        // Re-assert the step-up requirement at ISSUE time, exactly as the redirect_uri
        // and PKCE invariants above are. mount() ran against whatever session was
        // active then; the code is minted from whatever is active now.
        if ($this->unmetAuthenticationRequirement($session) !== null) {
            $this->error = 'This application requires a more recent or stronger sign-in. Please start again.';

            return;
        }

        $code = $codes->issue(
            $this->clientId,
            $me->id(),
            $me->organizationId(),
            $this->redirectUri,
            // array_values() on both `scopes` and `amr`: neither is guaranteed to still be
            // a list by the time it reaches the code row — one is rehydrated from the
            // request payload, the other from a JSON column. The re-key is load-bearing,
            // not decoration: the issued grant is serialised straight back to JSON, and an
            // object there is a wire-format change for every client that reads an array.
            array_values($this->scopes),
            $this->codeChallenge,
            $this->codeChallengeMethod,
            $this->nonce,
            $session?->created_at?->getTimestamp(),
            $session !== null ? array_values($session->amr) : [],
        );

        // RFC 9207: return the issuer in the authorization response so the client
        // can detect a mix-up (a code minted by a different AS than it expects).
        // Resolve the issuer the SAME way discovery and the id_token do. Reading
        // config('cbox-id.issuer') here returned the platform APEX, so a tenant on its
        // own host advertised one issuer and returned another in the authorization
        // response — and a mix-up-hardened RP (which is what RFC 9207 exists to serve)
        // compares the two and aborts the callback. Login was impossible for every
        // environment that was not the platform root.
        $params = [
            'code' => $code,
            'iss' => app(IssuerResolver::class)->issuer(),
        ];

        if ($this->state !== null) {
            $params['state'] = $this->state;
        }

        $this->redirect($this->buildRedirect($params));
    }

    public function deny(): void
    {
        if ($this->error !== null) {
            return;
        }

        // RFC 9207 §2: `iss` belongs on authorization responses INCLUDING error ones,
        // and a mix-up-hardened client MUST reject a response without it. Omitting it
        // here meant a user pressing "Deny" got an oauth4webapi/oidc-client-ts throw
        // instead of the "you declined" screen the RP wrote.
        $params = [
            'error' => 'access_denied',
            'iss' => app(IssuerResolver::class)->issuer(),
        ];

        if ($this->state !== null) {
            $params['state'] = $this->state;
        }

        $this->redirect($this->buildRedirect($params));
    }

    /**
     * Exact match, EXCEPT that a loopback redirect may use any port.
     *
     * RFC 8252 §7.3: a native app binds an ephemeral port at runtime, so the port it
     * registered once is not the port it listens on next time. A byte-exact comparison
     * rejected every such client on its second run. Scheme, host and path still must
     * match exactly — only the port floats, and only for 127.0.0.1 / [::1], never for a
     * remote host.
     *
     * @param  list<string>  $registered
     */
    private function redirectUriRegistered(string $candidate, array $registered): bool
    {
        if (in_array($candidate, $registered, true)) {
            return true;
        }

        $parts = parse_url($candidate);
        // parse_url returns an IPv6 host WITH its brackets ("[::1]"), so comparing
        // against the bare literal never matched and every [::1] native client fell back
        // to byte-exact matching — failing on its second run. Fails closed, but the
        // documented behaviour was untrue.
        $host = trim($parts['host'] ?? '', '[]');

        if (! in_array($host, ['127.0.0.1', '::1'], true) || ($parts['scheme'] ?? '') !== 'http') {
            return false;
        }

        foreach ($registered as $uri) {
            $r = parse_url($uri);

            if (($r['scheme'] ?? '') === 'http'
                && trim($r['host'] ?? '', '[]') === $host
                && ($r['path'] ?? '') === ($parts['path'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return an RFC 6749 §4.1.2.1 error to the CLIENT rather than rendering a page.
     *
     * Only safe once the redirect_uri has been matched against the client's registered
     * set — before that, redirecting would be an open redirect, which is why unknown
     * client / bad redirect_uri stay as rendered pages.
     */
    private function redirectError(string $redirectUri, string $error, ?string $state, string $description): void
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

        $this->redirectUri = $redirectUri;

        $this->redirect($this->buildRedirect($params), navigate: false);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function buildRedirect(array $params): string
    {
        $parts = parse_url($this->redirectUri);

        parse_str($parts['query'] ?? '', $existing);
        $query = http_build_query(array_merge($existing, $params));

        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '';
        $url .= '?'.$query;

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function parseScopes(string $scope): array
    {
        return array_values(array_filter(explode(' ', trim($scope)), fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $labels = [
            'openid' => 'Verify your identity',
            'profile' => 'Your name',
            'email' => 'Your email address',
            'offline_access' => 'Stay signed in',
        ];

        $rows = array_map(
            fn (string $scope): array => ['scope' => $scope, 'label' => $labels[$scope] ?? $scope],
            $this->scopes,
        );

        return [
            'me' => app(CurrentUser::class),
            'scopeRows' => $rows,
        ];
    }
}; ?>

<div>
    @if ($error)
        <div class="grid place-items-center rounded-full mb-5 text-lg font-bold" style="width:2.75rem;height:2.75rem;background:var(--danger-soft);color:var(--danger-strong)">
            !
        </div>
        <h1 class="text-2xl font-semibold tracking-tight">Authorization failed</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">{{ $error }}</p>
        <a href="{{ url('/') }}" class="btn btn-ghost w-full mt-6">Back to Cbox ID</a>
    @else
        <div class="grid place-items-center rounded-full mb-5" style="width:2.75rem;height:2.75rem;background:var(--accent-soft);color:var(--accent-strong)">
            <x-icon name="shield" class="w-5 h-5" />
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">Authorize {{ $clientName }}</h1>
        <p class="mt-1.5 text-sm" style="color:var(--muted)">
            <b>{{ $clientName }}</b> wants to access your Cbox ID account.
        </p>
        {{-- Provenance. An application's name is chosen by whoever registered it, so the
             name alone is not evidence of who is asking. --}}
        <p class="mt-1.5 text-xs" style="color:var(--faint)">
            Registered by <b style="color:var(--muted)">{{ $clientOwner }}</b> — an app's name is chosen by whoever registered it.
        </p>

        <div class="card mt-6 p-4 flex items-center gap-3">
            <span class="grid place-items-center rounded-full text-sm font-semibold" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent-strong)">
                {{ strtoupper(substr($me->name(), 0, 1)) }}
            </span>
            <div class="min-w-0">
                <p class="font-medium truncate">{{ $me->name() }}</p>
                <p class="text-xs truncate" style="color:var(--faint)">{{ $me->email() }}</p>
            </div>
        </div>

        @if (count($scopeRows) > 0)
            <p class="cbx-page-eyebrow mt-6">This will allow {{ $clientName }} to</p>
            <ul class="mt-2.5 space-y-2">
                @foreach ($scopeRows as $row)
                    <li class="flex items-center gap-2.5 text-sm">
                        <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success-strong)" />
                        <span>{{ $row['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-8 flex gap-3">
            <button type="button" wire:click="deny" class="btn btn-ghost flex-1" wire:loading.attr="disabled">Cancel</button>
            <button type="button" wire:click="approve" class="btn btn-primary flex-1" wire:loading.attr="disabled">Authorize</button>
        </div>

        <p class="mt-6 text-xs" style="color:var(--faint)">
            You'll be redirected to <span class="mono">{{ parse_url($redirectUri, PHP_URL_HOST) }}</span> after authorizing.
        </p>
    @endif
</div>
