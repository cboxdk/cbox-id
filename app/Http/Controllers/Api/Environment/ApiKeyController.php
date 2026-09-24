<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Environment\CustomerApiKeyResource;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Environment plane › customer API keys: the keys an organization's members created for
 * your apps' APIs. List them per organization, and revoke one — through the framework's
 * {@see CustomerApiKeys}, which records `api_key.revoked` with this key as the actor.
 *
 * Never the key material: a key is shown to its holder once, when it is made.
 */
final class ApiKeyController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request, string $id, TenantContext $tenants): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null) {
            return $this->notFound('organization');
        }

        [$limit, $after] = $this->cursor($request);
        $clientId = $request->filled('client_id') ? $request->string('client_id')->toString() : null;

        // Tenant-owned: read as this organization, and bound to it in the WHERE clause too.
        $rows = $tenants->runAs(GenericTenant::of($organization->id), fn (): Collection => $this->pageOf(
            CustomerApiKey::query()
                ->where('organization_id', $organization->id)
                ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId)),
            $limit,
            $after,
        )->get());

        return $this->page($rows, $limit, CustomerApiKeyResource::from(...));
    }

    /**
     * Revoke — idempotent: a key that is already revoked answers 204 as well. A key from
     * another environment does not resolve here at all.
     */
    public function destroy(string $id, CustomerApiKeys $keys): JsonResponse|Response
    {
        if ($keys->find($id) === null) {
            return $this->notFound('API key');
        }

        $keys->revoke($id, ApiKeyActor::service($this->actingKey()->id));

        return response()->noContent();
    }
}
