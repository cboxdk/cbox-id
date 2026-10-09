<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\DisconnectConnectedService;
use App\Http\Controllers\Console\RunsActions;
use App\Platform\CurrentUser;
use App\Platform\Invitations\AppReturnTargets;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Pipes\Contracts\PipeConnections;
use Cbox\Id\Pipes\Contracts\Pipes;
use Cbox\Id\Pipes\Exceptions\PipeConnectFailed;
use Cbox\Id\Pipes\Exceptions\PipeNotFound;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\PipeProviderCatalog;
use Cbox\Id\Pipes\ValueObjects\PipeConnectState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A person's CONNECTED SERVICES: the third-party accounts (GitHub, Google, Slack…) they
 * connected so an app here can act through them — and the flow that connects one.
 *
 * Hosted and translated, unlike the rest of My account, because the people who land here
 * are an app's end users, sent from that app with "Connect your GitHub account".
 *
 * The flow, end to end:
 *
 *  1. `GET  /account/connected-services/{provider}/connect` — what connecting means, in the
 *     person's language: the provider, what will be asked for, which app asked. An app
 *     deep-links here with `?client_id=…&return_to=…`.
 *  2. `POST` the same URL — CSRF-protected, so a third-party page cannot start a connect
 *     for somebody — starts the OAuth flow ({@see PipeConnections::start()}) and keeps its
 *     state in THIS session.
 *  3. `GET  …/{provider}/callback` — the provider's answer. The state must match the one in
 *     the session, the person must be the one who started it, and the code is exchanged
 *     with the PKCE verifier only this session holds.
 *
 * The tokens never pass through here; they land sealed in the token vault.
 */
final readonly class ConnectedServiceController extends PageController
{
    use RunsActions;

    public function index(Request $request): Response
    {
        $me = $this->me();
        $connections = PipeConnection::query()->where('user_id', $me)->get()->keyBy('provider');

        $services = Pipe::query()->orderBy('provider')->get()
            ->filter(fn (Pipe $pipe): bool => $pipe->enabled || $connections->has($pipe->provider))
            ->map(function (Pipe $pipe) use ($connections): array {
                /** @var PipeConnection|null $connection */
                $connection = $connections->get($pipe->provider);

                return [
                    'provider' => $pipe->provider,
                    'name' => PipeProviderCatalog::find($pipe->provider)->name ?? $pipe->provider,
                    'connected' => $connection !== null,
                    'needsReauth' => $connection !== null && ! $connection->isActive(),
                    'account' => $connection?->account_label,
                    'scopes' => $connection->scopes ?? [],
                    'connectedAt' => $connection?->connected_at?->toIso8601String(),
                    'connectHref' => $pipe->enabled ? route('account.pipes.connect', $pipe->provider) : null,
                    'disconnectHref' => $connection === null ? null : route('account.pipes.destroy', $pipe->provider),
                ];
            })
            ->values()
            ->all();

        return $this->page('auth/connected-services', __('auth.connected_services.title'), [
            'services' => $services,
            'accountHref' => route('account'),
        ]);
    }

    public function consent(Request $request, string $provider): Response
    {
        $this->me();
        $pipe = $this->pipe($provider);
        $app = $this->requestingApp($request, $pipe);

        return $this->page('auth/connect-service', __('auth.connect_service.title', ['provider' => $this->name($pipe)]), [
            'provider' => $pipe->provider,
            'providerName' => $this->name($pipe),
            'scopes' => $pipe->scopes,
            'appName' => $app?->name,
            'clientId' => $app?->client_id,
            'returnTo' => $app === null ? null : $this->returnTo($app, $request->query('return_to')),
            'authorizeHref' => route('account.pipes.authorize', $pipe->provider),
            'cancelHref' => route('account.pipes'),
        ]);
    }

    public function authorize(Request $request, string $provider, PipeConnections $connections): SymfonyResponse
    {
        $me = $this->me();
        $pipe = $this->pipe($provider);
        $app = $this->requestingApp($request, $pipe);

        try {
            $authorization = $connections->start($pipe->provider, $me, route('account.pipes.callback', $pipe->provider));
        } catch (PipeNotFound) {
            abort(404);
        }

        // Keyed by provider, and PULLED on the callback: a replayed callback finds nothing.
        $request->session()->put($this->stashKey($pipe->provider), [
            'flow' => $authorization->state->toArray(),
            'return_to' => $app === null ? null : $this->returnTo($app, $request->input('return_to')),
        ]);

        // A location visit: Inertia's XHR cannot follow a cross-origin redirect, so the
        // browser is told to go there itself. A plain form post gets a 302 the same way.
        return Inertia::location($authorization->url);
    }

    public function callback(Request $request, string $provider, PipeConnections $connections): RedirectResponse
    {
        $me = $this->me();
        $stash = $request->session()->pull($this->stashKey($provider));
        $flow = PipeConnectState::fromMixed(is_array($stash) ? ($stash['flow'] ?? null) : null);
        $returnTo = is_array($stash) && is_string($stash['return_to'] ?? null) ? $stash['return_to'] : null;
        $name = PipeProviderCatalog::find($provider)->name ?? $provider;

        // No flow in this session, or one somebody else started: nothing to finish.
        if ($flow === null || ! hash_equals($flow->userId, $me)) {
            return $this->finish(null, 'failed', $provider, __('auth.connected_services.failed', ['provider' => $name]));
        }

        // The person said no at the provider (`error=access_denied`) — not a failure of ours.
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->finish($returnTo, 'cancelled', $provider, __('auth.connected_services.cancelled', ['provider' => $name]));
        }

        try {
            $connections->complete($flow, (string) $request->query('state', ''), (string) $request->query('code', ''));
        } catch (PipeConnectFailed) {
            return $this->finish($returnTo, 'failed', $provider, __('auth.connected_services.failed', ['provider' => $name]));
        }

        return $this->finish($returnTo, 'connected', $provider, __('auth.connected_services.connected', ['provider' => $name]));
    }

    public function destroy(string $provider): RedirectResponse
    {
        $this->me();
        $name = PipeProviderCatalog::find($provider)->name ?? $provider;

        $result = $this->act(DisconnectConnectedService::class, ['provider' => $provider], ['provider' => 'disconnect'], 'disconnect');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('auth.connected_services.disconnected', ['provider' => $name]));
    }

    /**
     * Back to the app that sent the person, when it did and its address checks out; to the
     * person's list otherwise. The outcome rides along so the app can say what happened.
     */
    private function finish(?string $returnTo, string $outcome, string $provider, string $message): RedirectResponse
    {
        if ($returnTo !== null) {
            $separator = str_contains($returnTo, '?') ? '&' : '?';

            return redirect()->away($returnTo.$separator.http_build_query(['provider' => $provider, 'status' => $outcome]));
        }

        return to_route('account.pipes')->with($outcome === 'connected' ? 'status' : 'error', $message);
    }

    private function me(): string
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        return $me->id();
    }

    /** An ENABLED pipe of this environment, or a 404. */
    private function pipe(string $provider): Pipe
    {
        $pipe = app(Pipes::class)->forProvider($provider);

        abort_if($pipe === null || ! $pipe->enabled, 404);

        return $pipe;
    }

    private function name(Pipe $pipe): string
    {
        return PipeProviderCatalog::find($pipe->provider)->name ?? $pipe->provider;
    }

    /**
     * The app that sent the person here — only when it is an app of this environment
     * granted this pipe. Anything else is ignored rather than refused: the page works
     * without it, and naming an app the person never dealt with would be the lie.
     */
    private function requestingApp(Request $request, Pipe $pipe): ?Client
    {
        $clientId = $request->input('client_id', $request->query('client_id'));

        if (! is_string($clientId) || $clientId === '' || ! app(Pipes::class)->isGranted($pipe->id, $clientId)) {
            return null;
        }

        return Client::query()->where('client_id', $clientId)->first();
    }

    /** `$url` when it sits on one of the app's registered redirect origins, else null. */
    private function returnTo(Client $app, mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $origin = AppReturnTargets::origin(trim($url));

        if ($origin === null) {
            return null;
        }

        foreach ($app->redirect_uris as $uri) {
            if (AppReturnTargets::origin($uri) === $origin) {
                return trim($url);
            }
        }

        return null;
    }

    private function stashKey(string $provider): string
    {
        // Colons, not dots: a dot is a path separator to the session store.
        return 'pipes:connect:'.$provider;
    }
}
