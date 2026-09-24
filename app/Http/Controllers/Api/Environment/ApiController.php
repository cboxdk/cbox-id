<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\CreateApiRequest;
use App\Http\Requests\Api\Environment\UpdateApiRequest;
use App\Http\Resources\Environment\ApiResource;
use App\Platform\Apis\ApiAdministration;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
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
 * Every write goes through {@see ApiAdministration}, the service Developers › APIs on the
 * environment console uses, so a change leaves the same entry whichever door made it —
 * attributed here to the key.
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

    public function store(CreateApiRequest $request, ApiAdministration $admin): JsonResponse
    {
        $organizationId = $request->organizationId();

        if ($organizationId !== null && $this->organization($organizationId) === null) {
            return $this->refuse('organization_not_found', 'No organization with that organization_id exists in this environment.', 422);
        }

        try {
            $api = $admin->register($request->toApi(), $this->actor());
        } catch (InvalidApiDefinition $refused) {
            return $this->refuse('invalid_api', $refused->getMessage(), 422);
        }

        return $this->item(ApiResource::from($api), 201);
    }

    /**
     * All of it or none of it: a rename that lands while a scope in the same request is
     * refused would leave the API half-changed, with an error that names only the half
     * that did not happen.
     */
    public function update(UpdateApiRequest $request, string $id, Apis $apis, ApiAdministration $admin): JsonResponse
    {
        $api = $apis->find($id);

        if ($api === null) {
            return $this->notFound('API');
        }

        $actor = $this->actor();

        try {
            // Its entries are written inside the same transaction, so a refused request
            // leaves neither a change nor a line on the trail claiming one.
            DB::transaction(function () use ($request, $admin, $api, $actor): void {
                $admin->update(
                    $api,
                    $request->name() ?? $api->name,
                    $request->changesClient() ? $request->clientId() : $api->client_id,
                    $actor,
                );

                $scopes = $request->scopes();

                if ($scopes === null) {
                    return;
                }

                $keep = [];

                foreach ($scopes as $scope) {
                    $admin->defineScope($api, $scope, $actor);
                    $keep[] = $scope->key;
                }

                foreach (array_diff($this->scopeKeys($api), $keep) as $gone) {
                    $admin->removeScope($api, $gone, $actor);
                }
            });
        } catch (InvalidApiDefinition $refused) {
            return $this->refuse('invalid_api', $refused->getMessage(), 422);
        }

        return $this->item(ApiResource::from($apis->find($api->id) ?? $api));
    }

    /**
     * Delete the API and its scopes. Tokens already minted for it keep their `aud` until
     * they expire; clients holding its scope keys keep them as free text.
     */
    public function destroy(string $id, Apis $apis, ApiAdministration $admin): JsonResponse|Response
    {
        $api = $apis->find($id);

        if ($api === null) {
            return $this->notFound('API');
        }

        $admin->delete($api, $this->actor());

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
     * The management key, as the service that made the change.
     */
    private function actor(): AuditActor
    {
        return AuditActor::service($this->actingKey()->id);
    }
}
