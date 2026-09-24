<?php

declare(strict_types=1);

namespace App\Platform\Apis;

use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Cbox\Id\OAuthServer\ValueObjects\NewApi;

/**
 * Every change to an API, through whichever door, and the one trail it leaves.
 *
 * Two doors change APIs: Developers › APIs on the environment console, and
 * `/api/v1/apis` on the environment management API. {@see Apis} records nothing, so each
 * door used to record its own — under the same names but in different shapes (one targeted
 * the API's id, the other its identifier; one wrote a scope change as `api.scope_defined`,
 * the other folded it into `api.updated`). An auditor asking "who let organizations'
 * apps request `tax:assess`?" found the console's answer and missed the API's.
 *
 * ONE CHANGE, ONE ENTRY, THE SAME SHAPE FROM BOTH DOORS:
 *
 * - `api.created` — the API itself; each scope it was registered with is then its own
 *   `api.scope_defined`, exactly as if it had been added a moment later on the console;
 * - `api.updated` — name and/or linked app, as `changes: {field: {from, to}}`; nothing
 *   recorded when neither changed;
 * - `api.scope_defined` — a scope added, or its description or "organizations' apps may
 *   request this" changed (`from` names what it was); nothing recorded for a save that
 *   changed nothing, so the API's complete-set `PATCH` does not re-record every scope;
 * - `api.scope_removed`, `api.deleted`.
 *
 * The actor is whoever the door says: the administrator on the console, the management
 * key (as a service) on the API.
 */
final readonly class ApiAdministration
{
    public function __construct(
        private Apis $apis,
        private ApiAudit $audit,
    ) {}

    public function register(NewApi $definition, AuditActor $actor): Api
    {
        $api = $this->apis->register($definition);

        $this->audit->record(ApiAudit::CREATED, $api, $actor, array_filter([
            'client_id' => $api->client_id,
        ]));

        foreach ($api->scopes->sortBy('key') as $scope) {
            $this->audit->record(ApiAudit::SCOPE_DEFINED, $api, $actor, [
                'scope' => $scope->key,
                'description' => $scope->description,
                'tenant_requestable' => $scope->tenant_requestable,
            ]);
        }

        return $api;
    }

    /**
     * Rename the API and (re)link the app whose roles it enforces — one entry for both.
     */
    public function update(Api $api, string $name, ?string $clientId, AuditActor $actor): Api
    {
        $before = ['name' => $api->name, 'client_id' => $api->client_id];

        if ($name !== $api->name) {
            $this->apis->rename($api, $name);
        }

        if ($clientId !== $api->client_id) {
            $this->apis->linkClient($api, $clientId);
        }

        $changes = [];

        foreach (['name' => $api->name, 'client_id' => $api->client_id] as $field => $after) {
            if ($before[$field] !== $after) {
                $changes[$field] = ['from' => $before[$field], 'to' => $after];
            }
        }

        if ($changes !== []) {
            $this->audit->record(ApiAudit::UPDATED, $api, $actor, ['changes' => $changes]);
        }

        return $api;
    }

    /**
     * Add a scope, or change one this API already owns.
     */
    public function defineScope(Api $api, ApiScopeDefinition $definition, AuditActor $actor): ApiScope
    {
        $before = ApiScope::query()->where('api_id', $api->id)->where('key', $definition->key)->first();

        $scope = $this->apis->defineScope($api, $definition);

        if ($before !== null
            && $before->description === $scope->description
            && $before->tenant_requestable === $scope->tenant_requestable) {
            return $scope;
        }

        $context = [
            'scope' => $scope->key,
            'description' => $scope->description,
            'tenant_requestable' => $scope->tenant_requestable,
        ];

        if ($before !== null) {
            $context['from'] = [
                'description' => $before->description,
                'tenant_requestable' => $before->tenant_requestable,
            ];
        }

        $this->audit->record(ApiAudit::SCOPE_DEFINED, $api, $actor, $context);

        return $scope;
    }

    public function removeScope(Api $api, string $key, AuditActor $actor): void
    {
        $this->apis->removeScope($api, $key);

        $this->audit->record(ApiAudit::SCOPE_REMOVED, $api, $actor, ['scope' => $key]);
    }

    public function delete(Api $api, AuditActor $actor): void
    {
        // Recorded BEFORE the delete: the entry names the API, and afterwards there is no
        // API to name.
        $this->audit->record(ApiAudit::DELETED, $api, $actor, [
            'scopes' => ApiScope::query()->where('api_id', $api->id)->orderBy('key')->pluck('key')->values()->all(),
        ]);

        $this->apis->delete($api);
    }
}
