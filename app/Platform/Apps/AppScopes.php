<?php

declare(strict_types=1);

namespace App\Platform\Apps;

use App\Http\Props\Console\ApiScopeGroupProps;
use App\Http\Props\Console\ApiScopeOptionProps;
use App\Http\Props\Console\AppAudienceProps;
use App\Platform\ScopeCatalog;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\RegisteredApiAudienceResolver;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredScope;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;
use Cbox\Id\Organization\Models\Organization;

/**
 * WHAT AN APP MAY ASK FOR, in the three families the scope picker draws.
 *
 *  - The console's own catalogue ({@see ScopeCatalog}): sign-in scopes and this platform's
 *    own API.
 *  - The scopes of REGISTERED APIs, grouped by API — only those this app may hold, by the
 *    framework's one rule ({@see RegisteredScope::mayBeHeldBy()}). An organization's
 *    administrator is shown their own organization's APIs and the scopes the environment
 *    lets every organization's apps request, and nothing else: another organization's API
 *    is not a group they can see, never mind tick.
 *  - Free text, kept under Advanced. It is how the page stayed useful before APIs could be
 *    registered, and it still works — but a free-text scope owns nothing, so it can never
 *    carry a registered API's audience, and the page says so beside the field.
 *
 * THE PICKER IS NOT THE GUARD. What a crafted request sends is intersected with what the
 * page could have offered, and the framework refuses a registered scope the app may not
 * hold when the app is SAVED ({@see ScopeNotGrantable}), whichever door the write came
 * through. This class decides what is drawn; the model decides what is kept.
 */
final readonly class AppScopes
{
    public function __construct(
        private Apis $apis,
        private ScopeCatalog $catalog,
        private IssuerResolver $issuers,
    ) {}

    /**
     * Every registered API with at least one scope this app may hold, by name.
     *
     * @return list<ApiScopeGroupProps>
     */
    public function offered(ScopeHolder $holder): array
    {
        $groups = [];
        $apis = $this->apis->all();
        $owners = $this->ownerNames($apis->pluck('organization_id')->filter()->unique()->values()->all());

        foreach ($apis as $api) {
            $holdable = [];

            foreach ($api->scopes as $scope) {
                if ($this->registered($api, $scope)->mayBeHeldBy($holder)) {
                    $holdable[] = new ApiScopeOptionProps($scope->key, $scope->description);
                }
            }

            if ($holdable === []) {
                continue;
            }

            $groups[] = new ApiScopeGroupProps(
                id: $api->id,
                name: $api->name,
                identifier: $api->identifier,
                owner: $api->organization_id === null
                    ? 'This environment'
                    : ($owners[$api->organization_id] ?? 'An organization'),
                scopes: $holdable,
            );
        }

        return $groups;
    }

    /**
     * The app's stored scopes, split the way the page draws them: ticked in the catalogue,
     * ticked under their API, or typed. Anything neither the catalogue nor an API it may
     * hold accounts for is typed — including a registered scope it held from before the
     * API existed, so a save neither drops it in silence nor keeps it without saying.
     */
    public function split(Client $client): StoredScopes
    {
        $stored = array_values($client->scopes);
        $catalogue = array_values(array_intersect($stored, $this->catalog->keys()));
        $holder = ScopeHolder::of($client);

        $api = [];

        foreach ($this->registeredAmong($holder, array_values(array_diff($stored, $catalogue))) as $key => $scope) {
            if ($scope->mayBeHeldBy($holder)) {
                $api[] = $key;
            }
        }

        return new StoredScopes(
            catalogue: $catalogue,
            api: $api,
            custom: array_values(array_diff($stored, $catalogue, $api)),
        );
    }

    /**
     * What the page could have offered, out of what a request sent: catalogue keys and
     * registered keys. Anything else in the checkbox payload is not a box the page draws
     * and is dropped — typed scopes arrive through the free-text field, not here.
     *
     * A registered key is kept WHETHER OR NOT this app may hold it. Dropping the ones it
     * may not would turn a refusal into a save that quietly did less than was asked; kept,
     * the framework refuses them on save and the page says which and why.
     *
     * @param  list<string>  $chosen
     * @return list<string>
     */
    public function selectable(ScopeHolder $holder, array $chosen): array
    {
        $catalogue = array_values(array_intersect($chosen, $this->catalog->keys()));
        $registered = array_keys($this->registeredAmong($holder, array_values(array_diff($chosen, $catalogue))));

        return array_values(array_unique([...$catalogue, ...$registered]));
    }

    /**
     * The audience this app's tokens get when it asks without naming an API — worked out
     * by the token endpoint's own rule ({@see RegisteredApiAudienceResolver}),
     * so the page and the token cannot disagree.
     */
    public function audience(Client $client): AppAudienceProps
    {
        $holder = ScopeHolder::of($client);
        $stored = array_values($client->scopes);
        $registered = $this->registeredAmong($holder, $stored);

        $identifiers = [];
        $refused = [];

        foreach ($registered as $key => $scope) {
            if ($scope->mayBeHeldBy($holder)) {
                $identifiers[$scope->api->id] = $scope->api->identifier;
            } else {
                $refused[] = $key;
            }
        }

        $identifiers = array_values(array_unique($identifiers));
        sort($identifiers);

        $unowned = array_values(array_filter(
            $stored,
            fn (string $scope): bool => ! isset($registered[$scope])
                && ! ProtocolScope::isProtocol($scope)
                && ! in_array($scope, $this->catalog->keys(), true),
        ));

        return new AppAudienceProps(
            shape: match (count($identifiers)) {
                0 => AudienceShape::Issuer,
                1 => AudienceShape::Api,
                default => AudienceShape::Several,
            },
            issuer: rtrim($this->issuers->issuer(), '/'),
            identifiers: $identifiers,
            withIssuer: count($identifiers) === 1 && in_array(ProtocolScope::OpenId->value, $stored, true),
            unowned: $unowned,
            refused: $refused,
        );
    }

    /**
     * The framework's refusal, in words an administrator can act on.
     *
     * The exception says a scope "belongs to a registered API this client may not hold",
     * which is true and names neither the API nor what to do about it. Here each refused
     * scope says whose it is and why this app cannot have it — except that an API another
     * organization owns is not named, because naming it would tell this organization what
     * its neighbour runs.
     */
    public function explain(ScopeNotGrantable $refused, ScopeHolder $holder): string
    {
        $registered = $this->registeredAmong($holder, $refused->scopes);
        $sentences = [];

        foreach ($refused->scopes as $key) {
            $scope = $registered[$key] ?? null;

            if ($scope === null) {
                continue;
            }

            if ($scope->api->organizationId !== null) {
                $sentences[] = "\"{$key}\" belongs to an API another organization owns, and only that organization's apps may hold it.";

                continue;
            }

            $name = $this->apis->find($scope->api->id)->name ?? $scope->api->identifier;

            $sentences[] = "\"{$key}\" belongs to the {$name} API ({$scope->api->identifier}), which keeps it for this environment's own apps.";
        }

        if ($sentences === []) {
            return $refused->getMessage();
        }

        return 'This app may not hold '.(count($sentences) === 1 ? 'that scope' : 'those scopes').'. '
            .implode(' ', $sentences)
            .' Remove '.(count($sentences) === 1 ? 'it' : 'them').', or ask the environment\'s administrators to let organizations\' apps request '.(count($sentences) === 1 ? 'it' : 'them').'.';
    }

    /**
     * The registered scopes among `$keys`, keyed by key — asked of the app's OWN
     * environment, bound in the query rather than read off the ambient scope.
     *
     * @param  list<string>  $keys
     * @return array<string, RegisteredScope>
     */
    private function registeredAmong(ScopeHolder $holder, array $keys): array
    {
        if ($holder->environmentId === null || $keys === []) {
            return [];
        }

        return $this->apis->registeredScopes($holder->environmentId, $keys);
    }

    private function registered(Api $api, ApiScope $scope): RegisteredScope
    {
        return new RegisteredScope($scope->key, $api->audience(), $scope->tenant_requestable, $scope->description);
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<string, string>
     */
    private function ownerNames(array $ids): array
    {
        $ids = array_values(array_filter($ids, 'is_string'));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return Organization::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
