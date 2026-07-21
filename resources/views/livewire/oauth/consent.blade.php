<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Authorize'])] class extends Component
{
    // Validated request parameters. Locked: mount() validates these once against
    // the registered client (redirect_uri exact-match, PKCE, response_type). Livewire
    // lets the browser mutate public properties between requests, so without #[Locked]
    // an attacker could swap in an unregistered redirect_uri AFTER validation and
    // still have approve() mint a code — an open-redirect / code-exfiltration hole.
    #[Locked]
    public string $clientId = '';

    #[Locked]
    public string $clientName = '';

    #[Locked]
    public string $redirectUri = '';

    /** @var list<string> */
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
    ): void {
        $request = request();

        // RFC 9126: if the client pushed its request, take the parameters from the
        // single-use request_uri rather than the (untrusted, tamperable) query.
        $pushed = [];
        $requestUri = $request_uri ?? $request->query('request_uri');
        $requestClientId = $client_id ?? $request->query('client_id');
        if (is_string($requestUri) && $requestUri !== '' && is_string($requestClientId)) {
            $pushed = app(PushedAuthorizationRequests::class)->consume($requestClientId, $requestUri) ?? null;
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
        $stateParam = $from('state', $state);
        $codeChallenge = $from('code_challenge', $code_challenge);
        $codeChallengeMethod = $from('code_challenge_method', $code_challenge_method) ?? 'S256';
        $nonceParam = $from('nonce', $nonce);

        // response_type must be the authorization-code flow.
        if ($responseType !== 'code') {
            $this->error = 'Unsupported response_type. Only the authorization code flow is supported.';

            return;
        }

        // The client must exist.
        $client = is_string($clientId) && $clientId !== '' ? $clients->byClientId($clientId) : null;

        if (! $client instanceof Client) {
            $this->error = 'Unknown client. This application is not registered with Cbox ID.';

            return;
        }

        // The redirect_uri must exactly match one the client registered. Never
        // redirect to a URI we have not verified.
        if (! is_string($redirectUri) || ! in_array($redirectUri, $client->redirect_uris, true)) {
            $this->error = 'The redirect URI does not match any registered for this application.';

            return;
        }

        // PKCE is mandatory, and we only accept S256.
        if (! is_string($codeChallenge) || $codeChallenge === '') {
            $this->error = 'Missing PKCE code challenge. A code_challenge is required.';

            return;
        }

        if ($codeChallengeMethod !== 'S256') {
            $this->error = 'Unsupported code_challenge_method. Only S256 is supported.';

            return;
        }

        $this->clientId = $client->client_id;
        $this->clientName = $client->name;
        $this->redirectUri = $redirectUri;
        $this->scopes = $this->parseScopes(is_string($scopeParam) ? $scopeParam : '');
        $this->state = is_string($stateParam) ? $stateParam : null;
        $this->codeChallenge = $codeChallenge;
        $this->codeChallengeMethod = $codeChallengeMethod;
        $this->nonce = is_string($nonceParam) ? $nonceParam : null;

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
            $this->redirect($this->buildRedirect(array_filter([
                'error' => 'interaction_required',
                'error_description' => 'User interaction is required to authorize this request.',
                'state' => $this->state,
            ], static fn (?string $v): bool => $v !== null)));

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
        return route('oauth.authorize', array_filter([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'nonce' => $this->nonce,
            'reauthed' => '1',
        ], static fn (?string $v): bool => $v !== null && $v !== ''));
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
            || ! in_array($this->redirectUri, $client->redirect_uris, true)
            || $this->codeChallenge === ''
            || $this->codeChallengeMethod !== 'S256') {
            $this->error = 'This authorization request can no longer be completed. Please start again.';

            return;
        }

        $me = app(CurrentUser::class);

        // A suspended organization cannot authorize applications or mint tokens.
        if ($me->organization()?->status === OrganizationStatus::Suspended) {
            $this->error = 'This organization has been suspended and cannot authorize applications.';

            return;
        }

        $session = $me->session();

        $code = $codes->issue(
            $this->clientId,
            $me->id(),
            $me->organizationId(),
            $this->redirectUri,
            $this->scopes,
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

        $params = ['error' => 'access_denied'];

        if ($this->state !== null) {
            $params['state'] = $this->state;
        }

        $this->redirect($this->buildRedirect($params));
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
        <div class="grid place-items-center rounded-full mb-5 text-lg font-bold" style="width:2.75rem;height:2.75rem;background:var(--danger-soft);color:var(--danger)">
            !
        </div>
        <h1 class="text-2xl font-semibold tracking-tight">Authorization failed</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">{{ $error }}</p>
        <a href="{{ url('/') }}" class="btn btn-ghost w-full mt-6">Back to Cbox ID</a>
    @else
        <div class="grid place-items-center rounded-full mb-5" style="width:2.75rem;height:2.75rem;background:var(--accent-soft);color:var(--accent)">
            <x-icon name="shield" class="w-5 h-5" />
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">Authorize {{ $clientName }}</h1>
        <p class="mt-1.5 text-sm" style="color:var(--muted)">
            <b>{{ $clientName }}</b> wants to access your Cbox ID account.
        </p>

        <div class="card mt-6 p-4 flex items-center gap-3">
            <span class="grid place-items-center rounded-full text-sm font-semibold" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
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
                        <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success)" />
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
