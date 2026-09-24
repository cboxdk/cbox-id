<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Console\ApiRowProps;
use App\Http\Props\Console\ApiScopeProps;
use App\Http\Props\Console\OptionProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SaveApiScopeRequest;
use App\Http\Requests\Console\StoreApiRequest;
use App\Http\Requests\Console\UpdateApiRequest;
use App\Platform\Apis\ApiAdministration;
use App\Platform\Apis\ApiAudit;
use App\Platform\Help\HelpTopic;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\OAuthServer\Exceptions\InvalidApiDefinition;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Cbox\Id\OAuthServer\ValueObjects\NewApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
 * {@see Apis} records nothing on the audit trail, so every write goes through
 * {@see ApiAdministration}, the same service the management API's `/v1/apis` uses: one
 * change, one {@see ApiAudit} entry, the same shape whichever door made it.
 */
final readonly class ApiController extends ConsoleController
{
    public function index(Apis $apis): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $organizationId = $this->scope->organizationId();

        // An organization chosen in the switcher narrows the list to what concerns it: its
        // own APIs, and the environment's — the ones its apps might be given scopes of.
        $rows = $organizationId === null
            ? $apis->all()
            : $apis->ownedBy($organizationId)->concat($apis->ownedBy(null))->sortBy('name')->values();

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
            'createHref' => route('environment.apis.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $organizationId = $this->scope->organizationId();

        $owners = [new OptionProps('environment', 'This environment')];
        $apps = ['environment' => $this->linkableApps(null)];

        // An API is given to an organization the way an app is: by choosing the
        // organization in the console first. The list is never every organization in the
        // environment — that is unbounded — only the one being administered.
        if ($organizationId !== null) {
            $owners[] = new OptionProps($organizationId, (string) $this->scope->organizationName());
            $apps[$organizationId] = $this->linkableApps($organizationId);
        }

        return $this->page('console/apis/create', 'New API', [
            'owners' => $owners,
            'apps' => $apps,
            'organizationChosen' => $organizationId !== null,
            'indexHref' => route('environment.apis'),
            'storeHref' => route('environment.apis.store'),
        ]);
    }

    public function store(StoreApiRequest $request, Apis $apis, ApiAdministration $admin): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $owner = $request->owner();

        if ($owner === 'environment') {
            $organizationId = null;
        } elseif ($owner === $this->scope->organizationId()) {
            $organizationId = $owner;
        } else {
            // Only the environment, or the organization this console is acting on. A crafted
            // id — another organization's, or one from another environment — is refused
            // here rather than handed to the registry to find out.
            return back()->withInput()->withErrors(['owner' => 'Choose the environment, or the organization you are acting on.']);
        }

        if ($apis->identifiedBy($request->identifier()) !== null) {
            return back()->withInput()->withErrors(['identifier' => 'An API with this identifier is already registered in this environment.']);
        }

        $refusal = $this->linkRefusal($request->clientId(), $organizationId);

        if ($refusal !== null) {
            return back()->withInput()->withErrors(['clientId' => $refusal]);
        }

        try {
            $api = $admin->register(new NewApi(
                identifier: $request->identifier(),
                name: $request->name(),
                organizationId: $organizationId,
                clientId: $request->clientId(),
            ), $this->scope->auditActor());
        } catch (InvalidApiDefinition $refused) {
            return back()->withInput()->withErrors(['identifier' => $refused->getMessage()]);
        }

        return to_route('environment.apis.show', $api->id)
            ->with('status', 'API "'.$api->name.'" registered. Add the scopes it owns below.');
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

    public function update(UpdateApiRequest $request, string $api, ApiAdministration $admin): RedirectResponse
    {
        $model = $this->api($api);

        $refusal = $this->linkRefusal($request->clientId(), $model->organization_id);

        if ($refusal !== null) {
            return back()->withInput()->withErrors(['clientId' => $refusal]);
        }

        try {
            $admin->update($model, $request->name(), $request->clientId(), $this->scope->auditActor());
        } catch (InvalidApiDefinition $refused) {
            return back()->withInput()->withErrors(['name' => $refused->getMessage()]);
        }

        return back()->with('status', 'API saved.');
    }

    public function storeScope(SaveApiScopeRequest $request, string $api, ApiAdministration $admin): RedirectResponse
    {
        $model = $this->api($api);
        $key = $request->key();

        if (ProtocolScope::isProtocol($key)) {
            return back()->withInput()->withErrors(['key' => "\"{$key}\" is a sign-in scope Cbox ID defines itself, so no API can own it."]);
        }

        $existing = ApiScope::query()->where('key', $key)->first();

        if ($existing !== null) {
            return back()->withInput()->withErrors(['key' => $existing->api_id === $model->id
                ? "This API already owns \"{$key}\". Change it in the list below."
                : "\"{$key}\" already belongs to another API in this environment. A token request names a scope by its key alone, so each key can belong to one API."]);
        }

        return $this->define($model, new ApiScopeDefinition($key, $request->description(), $this->requestable($model, $request)), $admin, 'Scope "'.$key.'" added.');
    }

    public function updateScope(SaveApiScopeRequest $request, string $api, string $scope, ApiAdministration $admin): RedirectResponse
    {
        $model = $this->api($api);
        $row = $this->scopeOf($model, $scope);

        return $this->define($model, new ApiScopeDefinition($row->key, $request->description(), $this->requestable($model, $request)), $admin, 'Scope "'.$row->key.'" saved.');
    }

    public function destroyScope(string $api, string $scope, ApiAdministration $admin): RedirectResponse
    {
        $model = $this->api($api);
        $row = $this->scopeOf($model, $scope);

        $admin->removeScope($model, $row->key, $this->scope->auditActor());

        return back()->with('status', 'Scope "'.$row->key.'" removed. Apps that held it keep it as a typed scope, which no longer reaches this API.');
    }

    public function destroy(string $api, ApiAdministration $admin): RedirectResponse
    {
        $model = $this->api($api);

        $admin->delete($model, $this->scope->auditActor());

        return to_route('environment.apis')->with('status', 'API "'.$model->name.'" deleted.');
    }

    /**
     * Define (add or change) one scope; the service records what changed.
     */
    private function define(Api $model, ApiScopeDefinition $definition, ApiAdministration $admin, string $status): RedirectResponse
    {
        try {
            $admin->defineScope($model, $definition, $this->scope->auditActor());
        } catch (InvalidApiDefinition $refused) {
            return back()->withInput()->withErrors(['key' => $refused->getMessage()]);
        }

        return back()->with('status', $status);
    }

    /**
     * "Organizations' apps may request this" means something only on an API the
     * environment owns. On an organization's own API only that organization's apps may hold
     * its scopes whatever the flag says, so it is stored as the default rather than as a
     * choice the page never offered.
     */
    private function requestable(Api $api, SaveApiScopeRequest $request): bool
    {
        return $api->organization_id !== null || $request->tenantRequestable();
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
     * Why an app cannot be linked to an API with this owner, or null when it can.
     *
     * The registry refuses the same thing; asked here so the refusal lands on the picker
     * with a sentence. The rule is the framework's: the app must have the API's owner, or
     * one owner's roles and permissions would be stamped into tokens for another's API.
     */
    private function linkRefusal(?string $clientId, ?string $organizationId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $client = Client::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            return 'Choose one of the apps offered.';
        }

        if ($client->organization_id !== $organizationId || ($organizationId === null && $client->isDynamicallyRegistered())) {
            return 'Link an app with the same owner as the API. Tokens for this API carry the linked app\'s roles and permissions, so it has to be the owner\'s own app.';
        }

        return null;
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
