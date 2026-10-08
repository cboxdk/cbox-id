<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Apis\CreateApi;
use App\Actions\Apis\DefineApiScope;
use App\Actions\Apis\DeleteApi;
use App\Actions\Apis\RemoveApiScope;
use App\Actions\Apis\UpdateApi;
use App\Http\Props\Console\ApiRowProps;
use App\Http\Props\Console\ApiScopeProps;
use App\Http\Props\Console\OptionProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SaveApiScopeRequest;
use App\Http\Requests\Console\StoreApiRequest;
use App\Http\Requests\Console\UpdateApiRequest;
use App\Platform\Apis\ApiAudit;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * DEVELOPERS › APIS — the services of yours that receive access tokens, and the scopes each
 * of them owns.
 *
 * WHY THIS EXISTS. A scope used to be free text on each app, so anybody who could edit an
 * app — an organization's administrator on their own console — could type `tax:assess`
 * onto it and be handed a token whose `aud` and `scope` said exactly what the tax API was
 * waiting to read. Registering the API makes its scopes its owner's to hand out: the
 * framework refuses to save a scope on an app whose owner may not hold it, and a free-text
 * scope can never ride on a registered API's audience.
 *
 * THE ENVIRONMENT CONSOLE ONLY, in this version, and that is a decision rather than drift.
 * An identifier and a scope key are FIRST COME per environment — a token request names a
 * scope by key alone, so a key can belong to one API. If an organization's administrator
 * could register APIs, the first to register `https://tax.example.com` or `tax:read` would
 * own it, and could squat the audience and the scopes another organization, or the
 * environment itself, meant to use. So the environment's administrators register APIs, and
 * assign one to an organization when it is that organization's.
 *
 * Every write is an ACTION (`App\Actions\Apis\*`): the same class the management API's
 * `/v1/apis` and MCP run, so a change is checked, refused and recorded the same way
 * whichever door made it — one {@see ApiAudit} entry, the same shape. This controller only
 * maps a form to the action's input and its refusal back onto the form.
 */
final readonly class ApiController extends ConsoleController
{
    public function index(Apis $apis): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $filter = $this->organizationFilter();
        $organizationId = $filter->id;

        // Filtered to an organization, the list is what concerns it: its own APIs, and the
        // environment's — the ones its apps might be given scopes of. A filter naming no
        // organization here is an empty list.
        $rows = match (true) {
            $filter->unknown => new Collection,
            $organizationId === null => $apis->all(),
            default => $apis->ownedBy($organizationId)->concat($apis->ownedBy(null))->sortBy('name')->values(),
        };

        $owners = $this->scope->organizationNames($rows->pluck('organization_id'));
        $apps = Client::query()
            ->whereIn('client_id', $rows->pluck('client_id')->filter()->values()->all())
            ->pluck('name', 'client_id')
            ->all();

        return $this->page('console/apis/index', 'APIs', [
            'help' => HelpProps::for(HelpTopic::Apis),
            'apis' => $rows->map(fn (Api $api): ApiRowProps => new ApiRowProps(
                id: $api->id,
                name: $api->name,
                identifier: $api->identifier,
                owner: $this->ownerLabel($api->organization_id, $owners),
                linkedApp: $api->client_id === null ? null : (is_string($apps[$api->client_id] ?? null) ? $apps[$api->client_id] : $api->client_id),
                scopeCount: $api->scopes->count(),
                href: route('environment.apis.show', $api->id),
            ))->values()->all(),
            'organizationFilter' => $this->organizationFilterProps($filter),
            'createHref' => $this->createUrl('apis.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $organizationId = $this->prefilledOrganizationId();

        $owners = [new OptionProps('environment', 'This environment')];
        $apps = ['environment' => $this->linkableApps(null)];

        /*
         * An API is given to an organization by naming it — the form's "For which
         * organization?", which reloads this page with `?organization=` so the apps it can
         * be linked to are that organization's. The owners offered are never every
         * organization in the environment — that is unbounded — only the one named.
         */
        if ($organizationId !== null) {
            $name = Organization::query()->whereKey($organizationId)->value('name');
            $owners[] = new OptionProps($organizationId, is_string($name) ? $name : $organizationId);
            $apps[$organizationId] = $this->linkableApps($organizationId);
        }

        return $this->page('console/apis/create', 'New API', [
            'owners' => $owners,
            'apps' => $apps,
            'organization' => $this->organizationPicker(),
            'indexHref' => route('environment.apis'),
            'storeHref' => route('environment.apis.store'),
        ]);
    }

    public function store(StoreApiRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $owner = $request->owner();

        if ($owner === 'environment') {
            $organizationId = null;
        } else {
            // An organization of THIS environment, or a field error — never a write into
            // whichever organization a posted id happened to name somewhere else.
            $request->merge(['organization' => $owner]);
            $organizationId = $this->chosenOrganizationId($request, field: 'owner');
        }

        $result = $this->act(CreateApi::class, [
            'identifier' => $request->identifier(),
            'name' => $request->name(),
            'organization_id' => $organizationId,
            'client_id' => $request->clientId(),
        ], ['identifier' => 'identifier', 'name' => 'name', 'client_id' => 'clientId', 'organization_id' => 'owner'], 'identifier');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Api $created */
        $created = $result->value;

        return to_route('environment.apis.show', $created->id)
            ->with('status', 'API "'.$created->name.'" registered. Add the scopes it owns below.');
    }

    public function show(string $api): Response
    {
        $model = $this->api($api);
        $owners = $this->scope->organizationNames([$model->organization_id]);

        return $this->page('console/apis/show', $model->name, [
            'api' => [
                'id' => $model->id,
                'name' => $model->name,
                'identifier' => $model->identifier,
                'owner' => $this->ownerLabel($model->organization_id, $owners),
                'environmentOwned' => $model->organization_id === null,
                'clientId' => $model->client_id ?? '',
            ],
            'scopes' => $model->scopes->map(fn (ApiScope $scope): ApiScopeProps => ApiScopeProps::of(
                $scope,
                route('environment.apis.scopes.update', ['api' => $model->id, 'scope' => $scope->id]),
                route('environment.apis.scopes.destroy', ['api' => $model->id, 'scope' => $scope->id]),
            ))->values()->all(),
            'apps' => $this->linkableApps($model->organization_id),
            'indexHref' => route('environment.apis'),
            'urls' => [
                'update' => route('environment.apis.update', $model->id),
                'scopes' => route('environment.apis.scopes.store', $model->id),
                'destroy' => route('environment.apis.destroy', $model->id),
            ],
        ]);
    }

    public function update(UpdateApiRequest $request, string $api): RedirectResponse
    {
        $model = $this->api($api);

        $result = $this->act(UpdateApi::class, [
            'id' => $model->id,
            'name' => $request->name(),
            'client_id' => $request->clientId(),
        ], ['name' => 'name', 'client_id' => 'clientId'], 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'API saved.');
    }

    public function storeScope(SaveApiScopeRequest $request, string $api): RedirectResponse
    {
        $model = $this->api($api);
        $key = $request->key();

        // The console ADDS here and edits in the list below, so a key this API already owns
        // is pointed there rather than silently changed; the action itself would upsert.
        if (ApiScope::query()->where('api_id', $model->id)->where('key', $key)->exists()) {
            return back()->withInput()->withErrors(['key' => "This API already owns \"{$key}\". Change it in the list below."]);
        }

        return $this->define($model, $key, $request, 'Scope "'.$key.'" added.');
    }

    public function updateScope(SaveApiScopeRequest $request, string $api, string $scope): RedirectResponse
    {
        $model = $this->api($api);
        $row = $this->scopeOf($model, $scope);

        return $this->define($model, $row->key, $request, 'Scope "'.$row->key.'" saved.');
    }

    public function destroyScope(string $api, string $scope): RedirectResponse
    {
        $model = $this->api($api);
        $row = $this->scopeOf($model, $scope);

        $result = $this->act(RemoveApiScope::class, ['id' => $model->id, 'key' => $row->key]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Scope "'.$row->key.'" removed. Apps that held it keep it as a typed scope, which no longer reaches this API.');
    }

    public function destroy(string $api): RedirectResponse
    {
        $model = $this->api($api);

        $result = $this->act(DeleteApi::class, ['id' => $model->id]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.apis')->with('status', 'API "'.$model->name.'" deleted.');
    }

    /**
     * Define (add or change) one scope through the action, which records what changed.
     */
    private function define(Api $model, string $key, SaveApiScopeRequest $request, string $status): RedirectResponse
    {
        $result = $this->act(DefineApiScope::class, [
            'id' => $model->id,
            'key' => $key,
            'description' => $request->description(),
            'tenant_requestable' => $request->tenantRequestable(),
        ], ['key' => 'key', 'description' => 'description', 'tenant_requestable' => 'tenantRequestable'], 'key');

        return $result instanceof RedirectResponse ? $result : back()->with('status', $status);
    }

    /**
     * The API, in THIS environment, for an environment administrator — or 404.
     *
     * Every action asks here first, so no action can forget the gate: the environment
     * console's alone, and bounded by the model's environment scope, so an id from
     * another environment resolves to nothing.
     */
    private function api(string $id): Api
    {
        $this->scope->assertMayAdministerEnvironment();

        $api = Api::query()->with('scopes')->whereKey($id)->first();

        abort_if($api === null, 404);

        return $api;
    }

    /**
     * One scope of THIS API. Bound to the API in the query, so a scope id from a different
     * API cannot be edited through this one's URL.
     */
    private function scopeOf(Api $api, string $id): ApiScope
    {
        $scope = ApiScope::query()->where('api_id', $api->id)->whereKey($id)->first();

        abort_if($scope === null, 404);

        return $scope;
    }

    /**
     * The apps an API with this owner may enforce the roles of: the owner's own, and never
     * an app that registered itself.
     *
     * @return list<OptionProps>
     */
    private function linkableApps(?string $organizationId): array
    {
        return array_values(Client::query()
            ->when(
                $organizationId === null,
                fn (Builder $q): Builder => $q->whereNull('organization_id')->whereNull('registration_access_token_hash'),
                fn (Builder $q): Builder => $q->where('organization_id', $organizationId),
            )
            ->orderBy('name')
            ->get(['client_id', 'name'])
            ->map(fn (Client $client): OptionProps => new OptionProps($client->client_id, $client->name))
            ->all());
    }

    /**
     * @param  array<string, string>  $owners
     */
    private function ownerLabel(?string $organizationId, array $owners): string
    {
        return $organizationId === null ? 'This environment' : ($owners[$organizationId] ?? 'An organization');
    }
}
