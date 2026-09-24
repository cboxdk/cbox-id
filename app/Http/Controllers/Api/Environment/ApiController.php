<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\CreateApiRequest;
use App\Http\Requests\Api\Environment\UpdateApiRequest;
use App\Http\Resources\Environment\ApiResource;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Exceptions\InvalidApiDefinition;
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Environment plane › APIs (resource servers) and the scopes they own, over the framework's
 * {@see Apis}.
 *
 * REGISTERING AN API IS THE ENVIRONMENT'S ACT. An API's identifier becomes the `aud` of
 * tokens for it and its scope keys are unique across the environment, so no tenant surface
 * creates one; this API may, and may make one an organization's (`organization_id`).
 *
 * The framework's registry writes no audit entry of its own, so `api.created`,
 * `api.updated` and `api.deleted` are recorded here, attributed to the key.
 */
final class ApiController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request): JsonResponse
    {
        [$limit, $after] = $this->cursor($request);

        return $this->page(
            $this->pageOf(Api::query()->with('scopes'), $limit, $after)->get(),
            $limit,
            ApiResource::from(...),
        );
    }

    public function show(string $id, Apis $apis): JsonResponse
    {
        $api = $apis->find($id);

        return $api === null ? $this->notFound('API') : $this->item(ApiResource::from($api));
    }

    public function store(CreateApiRequest $request, Apis $apis, AuditLog $audit): JsonResponse
    {
        $organizationId = $request->organizationId();

        if ($organizationId !== null && $this->organization($organizationId) === null) {
            return $this->refuse('organization_not_found', 'No organization with that organization_id exists in this environment.', 422);
        }

        try {
            $api = $apis->register($request->toApi());
        } catch (InvalidApiDefinition $refused) {
            return $this->refuse('invalid_api', $refused->getMessage(), 422);
        }

        $this->record($audit, 'api.created', $api, ['identifier' => $api->identifier, 'scopes' => $this->scopeKeys($api)]);

        return $this->item(ApiResource::from($api), 201);
    }

    /**
     * All of it or none of it: a rename that lands while a scope in the same request is
     * refused would leave the API half-changed, with an error that names only the half
     * that did not happen.
     */
    public function update(UpdateApiRequest $request, string $id, Apis $apis, AuditLog $audit): JsonResponse
    {
        $api = $apis->find($id);

        if ($api === null) {
            return $this->notFound('API');
        }

        $before = $this->scopeKeys($api);

        try {
            DB::transaction(function () use ($request, $apis, $api): void {
                $name = $request->name();

                if ($name !== null && $name !== $api->name) {
                    $apis->rename($api, $name);
                }

                if ($request->changesClient() && $request->clientId() !== $api->client_id) {
                    $apis->linkClient($api, $request->clientId());
                }

                $scopes = $request->scopes();

                if ($scopes === null) {
                    return;
                }

                $keep = [];

                foreach ($scopes as $scope) {
                    $apis->defineScope($api, $scope);
                    $keep[] = $scope->key;
                }

                foreach (array_diff($this->scopeKeys($api), $keep) as $gone) {
                    $apis->removeScope($api, $gone);
                }
            });
        } catch (InvalidApiDefinition $refused) {
            return $this->refuse('invalid_api', $refused->getMessage(), 422);
        }

        $fresh = $apis->find($api->id) ?? $api;

        $this->record($audit, 'api.updated', $fresh, [
            'name' => $fresh->name,
            'client_id' => $fresh->client_id,
            'scopes_added' => array_values(array_diff($this->scopeKeys($fresh), $before)),
            'scopes_removed' => array_values(array_diff($before, $this->scopeKeys($fresh))),
        ]);

        return $this->item(ApiResource::from($fresh));
    }

    /**
     * Delete the API and its scopes. Tokens already minted for it keep their `aud` until
     * they expire; clients holding its scope keys keep them as free text.
     */
    public function destroy(string $id, Apis $apis, AuditLog $audit): JsonResponse|Response
    {
        $api = $apis->find($id);

        if ($api === null) {
            return $this->notFound('API');
        }

        $keys = $this->scopeKeys($api);

        $apis->delete($api);

        $this->record($audit, 'api.deleted', $api, ['identifier' => $api->identifier, 'scopes' => $keys]);

        return response()->noContent();
    }

    /**
     * @return list<string>
     */
    private function scopeKeys(Api $api): array
    {
        return array_values(ApiScope::query()
            ->where('api_id', $api->id)
            ->orderBy('key')
            ->get(['key'])
            ->map(static fn (ApiScope $scope): string => $scope->key)
            ->all());
    }

    /**
     * On the owning organization's trail, or the environment's for an environment-owned API.
     *
     * @param  array<string, mixed>  $context
     */
    private function record(AuditLog $audit, string $action, Api $api, array $context): void
    {
        $audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::Service,
            actorId: $this->actingKey()->id,
            organizationId: $api->organization_id,
            targetType: 'api',
            targetId: $api->id,
            context: $context,
        ));
    }
}
