<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\AuthorizationCodes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Models\Client;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Authorize'])] class extends Component
{
    // Validated request parameters (only populated once the request passes checks).
    public string $clientId = '';

    public string $clientName = '';

    public string $redirectUri = '';

    /** @var list<string> */
    public array $scopes = [];

    public ?string $state = null;

    public string $codeChallenge = '';

    public string $codeChallengeMethod = 'S256';

    public ?string $nonce = null;

    // Set when the request is malformed or the client/redirect_uri cannot be trusted.
    public ?string $error = null;

    /**
     * Query params arrive here from the route (real request) or from Volt::test's
     * second argument (mount params). Anything not supplied falls back to the
     * current request's query string.
     */
    public function mount(
        ClientRegistry $clients,
        ?string $client_id = null,
        ?string $redirect_uri = null,
        ?string $response_type = null,
        ?string $scope = null,
        ?string $state = null,
        ?string $code_challenge = null,
        ?string $code_challenge_method = null,
        ?string $nonce = null,
    ): void {
        $request = request();

        // RFC 9126: if the client pushed its request, take the parameters from the
        // single-use request_uri rather than the (untrusted, tamperable) query.
        $pushed = [];
        $requestUri = $request->query('request_uri');
        $requestClientId = $client_id ?? $request->query('client_id');
        if (is_string($requestUri) && $requestUri !== '' && is_string($requestClientId)) {
            $pushed = app(PushedAuthorizationRequests::class)->consume($requestClientId, $requestUri) ?? null;
            if ($pushed === null) {
                $this->error = 'This authorization request has expired or was already used. Please start again.';

                return;
            }
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
    }

    public function approve(AuthorizationCodes $codes): void
    {
        if ($this->error !== null) {
            return;
        }

        $me = app(CurrentUser::class);
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

        $params = ['code' => $code];

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
            <p class="mt-6 text-xs font-semibold uppercase tracking-wide" style="color:var(--faint)">This will allow {{ $clientName }} to</p>
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
            You'll be redirected to {{ parse_url($redirectUri, PHP_URL_HOST) }} after authorizing.
        </p>
    @endif
</div>
