<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Pipes\CreatePipe;
use App\Actions\Pipes\DeletePipe;
use App\Actions\Pipes\DisconnectPipeConnection;
use App\Actions\Pipes\GrantPipeAccess;
use App\Actions\Pipes\RevokePipeAccess;
use App\Actions\Pipes\UpdatePipe;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\Models\PipeGrant;
use Cbox\Id\Pipes\PipeProviderCatalog;
use Cbox\Id\Pipes\ValueObjects\PipeProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Developers › Pipes: the third-party providers people in this environment can connect
 * their own accounts at, which of the environment's apps may lease those tokens, and who
 * has connected.
 *
 * Every write is an action ({@see CreatePipe}, {@see UpdatePipe}, …) — this controller
 * renders and adapts, the same writes are on the REST API and MCP. The client secret goes
 * in on a form and never comes back out on a page.
 */
final readonly class PipeController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** Form field → action field, so a refusal lands under the input it is about. */
    private const FIELDS = [
        'provider' => 'provider',
        'client_id' => 'client_id',
        'client_secret' => 'client_secret',
        'scopes' => 'scopes',
        'parameters' => 'parameters',
        'parameters.tenant' => 'parameters',
        'parameters.domain' => 'parameters',
        'enabled' => 'enabled',
    ];

    public function index(): Response
    {
        $this->scope->assertMayAdminister();

        $configured = Pipe::query()->orderBy('provider')->get()->keyBy('provider');
        $counts = [];

        foreach (PipeConnection::query()->get(['pipe_id']) as $connection) {
            $counts[$connection->pipe_id] = ($counts[$connection->pipe_id] ?? 0) + 1;
        }

        return $this->page('console/pipes/index', Vocabulary::PIPES, [
            'help' => HelpProps::for(HelpTopic::Pipes),
            'providers' => array_map(function (PipeProvider $provider) use ($configured, $counts): array {
                /** @var Pipe|null $pipe */
                $pipe = $configured->get($provider->key);

                return [
                    'key' => $provider->key,
                    'name' => $provider->name,
                    'configured' => $pipe !== null,
                    'enabled' => $pipe !== null && $pipe->enabled,
                    'connections' => $pipe === null ? 0 : ($counts[$pipe->id] ?? 0),
                    'grants' => $pipe === null ? 0 : PipeGrant::query()->where('pipe_id', $pipe->id)->count(),
                    'href' => $pipe === null
                        ? $this->url('pipes.create').'?provider='.$provider->key
                        : $this->url('pipes.show', $pipe->id),
                ];
            }, PipeProviderCatalog::all()),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $chosen = PipeProviderCatalog::find($request->string('provider')->toString()) ?? PipeProviderCatalog::all()[0];
        $taken = Pipe::query()->pluck('provider')->all();

        return $this->page('console/pipes/create', 'New pipe', [
            'providers' => array_values(array_map(fn (PipeProvider $p): array => $this->provider($p), array_filter(
                PipeProviderCatalog::all(),
                static fn (PipeProvider $p): bool => ! in_array($p->key, $taken, true),
            ))),
            'selected' => in_array($chosen->key, $taken, true) ? null : $chosen->key,
            'indexHref' => $this->url('pipes'),
            'storeHref' => $this->url('pipes.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(CreatePipe::class, [
            'provider' => $request->string('provider')->toString(),
            'client_id' => $request->string('client_id')->toString(),
            'client_secret' => $request->string('client_secret')->toString(),
            ...$this->scopesInput($request),
            ...$this->parametersInput($request),
        ], self::FIELDS, fallback: 'provider');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Pipe $pipe */
        $pipe = $result->value;

        return to_route($this->routeName('pipes.show'), $pipe->id)
            ->with('status', 'Pipe configured — the client secret is sealed and never shown again. Grant an app access to start leasing tokens.');
    }

    public function show(Request $request, string $pipe): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($pipe);
        $entry = $model->catalogueEntry();
        $granted = PipeGrant::query()->where('pipe_id', $model->id)->orderBy('client_id')->get()
            ->map(static fn (PipeGrant $grant): string => $grant->client_id)
            ->all();
        $apps = Client::query()->orderBy('name')->get(['client_id', 'name'])->keyBy('client_id');

        $connections = PipeConnection::query()
            ->where('pipe_id', $model->id)
            ->orderByDesc('connected_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return $this->page('console/pipes/show', $entry->name ?? $model->provider, [
            'help' => HelpProps::for(HelpTopic::Pipes),
            'pipe' => [
                'id' => $model->id,
                'provider' => $model->provider,
                'name' => $entry->name ?? $model->provider,
                'clientId' => $model->client_id,
                'scopes' => $model->scopes,
                'parameters' => (object) $model->parameterValues(),
                'enabled' => $model->enabled,
                'redirectUri' => route('account.pipes.callback', $model->provider),
                'connectUrl' => route('account.pipes.connect', $model->provider),
                'leaseUrl' => url('/api/v1/vault/pipes/'.$model->provider.'/token'),
            ],
            'catalogue' => $entry === null ? null : $this->provider($entry),
            'grants' => array_map(fn (string $clientId): array => [
                'clientId' => $clientId,
                'name' => $apps->get($clientId)?->name,
                'revokeHref' => $this->url('pipes.grants.destroy', ['pipe' => $model->id, 'client' => $clientId]),
            ], $granted),
            'grantableApps' => $apps->except($granted)->map(static fn (Client $client): array => [
                'clientId' => $client->client_id,
                'name' => $client->name,
            ])->values()->all(),
            'connections' => array_map(fn (PipeConnection $connection): array => [
                'id' => $connection->id,
                'userId' => $connection->user_id,
                'account' => $connection->account_label,
                'status' => $connection->status->value,
                'reauthReason' => $connection->reauth_reason,
                'lastError' => $connection->last_error,
                'connectedAt' => $connection->connected_at?->diffForHumans(),
                'expiresAt' => $connection->access_expires_at?->diffForHumans(),
                'disconnectHref' => $this->url('pipes.connections.destroy', ['pipe' => $model->id, 'connection' => $connection->id]),
            ], $connections->getCollection()->all()),
            'pagination' => PaginationProps::from($connections),
            'indexHref' => $this->url('pipes'),
            'urls' => [
                'update' => $this->url('pipes.update', $model->id),
                'destroy' => $this->url('pipes.destroy', $model->id),
                'grant' => $this->url('pipes.grants.store', $model->id),
            ],
        ]);
    }

    public function update(Request $request, string $pipe): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->resolve($pipe);
        $input = ['id' => $model->id];

        foreach (['client_id', 'client_secret'] as $field) {
            if ($request->filled($field)) {
                $input[$field] = $request->string($field)->toString();
            }
        }

        if ($request->has('enabled')) {
            $input['enabled'] = $request->boolean('enabled');
        }

        $result = $this->act(UpdatePipe::class, [
            ...$input,
            ...($request->has('scopes') ? $this->scopesInput($request) : []),
            ...($request->has('parameters') ? $this->parametersInput($request) : []),
        ], self::FIELDS, fallback: 'client_id');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Pipe updated.');
    }

    public function destroy(string $pipe): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(DeletePipe::class, ['id' => $this->resolve($pipe)->id]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return to_route($this->routeName('pipes'))->with('status', 'Pipe removed — every connection through it was revoked here.');
    }

    public function grant(Request $request, string $pipe): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(GrantPipeAccess::class, [
            'id' => $this->resolve($pipe)->id,
            'client_id' => $request->string('client_id')->toString(),
        ], ['client_id' => 'client_id'], fallback: 'client_id');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Access granted — that app can now lease tokens through this pipe.');
    }

    public function revokeGrant(string $pipe, string $client): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(RevokePipeAccess::class, ['id' => $this->resolve($pipe)->id, 'client_id' => $client]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Access revoked.');
    }

    public function disconnect(string $pipe, string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(DisconnectPipeConnection::class, ['id' => $this->resolve($pipe)->id, 'connection_id' => $connection]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Account disconnected.');
    }

    /** A pipe of THIS environment, or a 404 — re-resolved on every request. */
    private function resolve(string $pipe): Pipe
    {
        $model = Pipe::query()->whereKey($pipe)->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The scopes typed into the form — one field, separated by spaces, commas or lines.
     *
     * @return array{scopes?: list<string>}
     */
    private function scopesInput(Request $request): array
    {
        $raw = $request->input('scopes');

        if (is_array($raw)) {
            return ['scopes' => array_values(array_filter($raw, 'is_string'))];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return ['scopes' => array_values(array_filter(preg_split('/[\s,]+/', trim($raw)) ?: []))];
    }

    /**
     * @return array{parameters?: array<string, string>}
     */
    private function parametersInput(Request $request): array
    {
        $raw = $request->input('parameters');

        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $clean[$key] = trim($value);
            }
        }

        return $clean === [] ? [] : ['parameters' => $clean];
    }

    /**
     * A catalogue entry, as the create form and the detail page draw it.
     *
     * @return array<string, mixed>
     */
    private function provider(PipeProvider $provider): array
    {
        return [
            'key' => $provider->key,
            'name' => $provider->name,
            'defaultScopes' => $provider->defaultScopes,
            'refreshable' => $provider->refreshable,
            'revokes' => $provider->revocation !== null,
            'apiBaseUrl' => $provider->apiBaseUrl,
            'documentationUrl' => $provider->documentationUrl,
            'setupSteps' => $provider->setupSteps,
            'redirectUri' => route('account.pipes.callback', $provider->key),
            'parameters' => array_map(static fn ($p): array => [
                'key' => $p->key,
                'label' => $p->label,
                'default' => $p->default,
                'help' => $p->help,
            ], $provider->parameters),
        ];
    }
}
