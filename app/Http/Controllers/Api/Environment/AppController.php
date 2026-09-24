<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\CreateAppRequest;
use App\Http\Resources\Environment\AppResource;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Environment plane › apps (OAuth clients): list them, register one, and export one's
 * configuration as a blueprint — the document `POST /v1/apps` accepts in another
 * environment, which is how an app is promoted from staging to production.
 *
 * Every write goes through the framework's {@see ClientRegistry}, which validates the
 * settings, mints the credentials and records `app.created` — attributed to this key.
 */
final class AppController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request): JsonResponse
    {
        [$limit, $after] = $this->cursor($request);

        return $this->page(
            $this->pageOf(Client::query(), $limit, $after)->get(),
            $limit,
            static fn (Client $client): array => AppResource::from($client),
        );
    }

    /**
     * Register the app. The response carries `client_secret` for a confidential app that
     * authenticates with one — ONCE: only its hash is kept.
     */
    public function store(CreateAppRequest $request, ClientRegistry $clients): JsonResponse
    {
        $organizationId = $request->organizationId();

        if ($organizationId !== null && $this->organization($organizationId) === null) {
            return $this->refuse('organization_not_found', 'No organization with that organization_id exists in this environment.', 422);
        }

        try {
            $registered = $clients->import(
                $request->blueprint(),
                $organizationId,
                $request->jwks(),
                AuditActor::service($this->actingKey()->id),
            );
        } catch (InvalidClientMetadata $refused) {
            return $this->refuse($refused->error, $refused->getMessage(), 422);
        } catch (ScopeNotGrantable $refused) {
            return $this->refuse('scope_not_grantable', $refused->getMessage(), 422);
        }

        return $this->item(AppResource::from($registered->client, $registered->secret), 201);
    }

    /**
     * The app's configuration without its identity or credentials: no client id, no secret,
     * no key set, no owning organization. The body is the blueprint document itself, under
     * `data`, exactly as `POST /v1/apps` takes it back.
     */
    public function blueprint(string $id, ClientRegistry $clients): JsonResponse
    {
        $client = $this->client($id);

        return $client === null
            ? $this->notFound('app')
            : $this->item($clients->blueprint($client)->toArray());
    }

    /**
     * By row id or by `client_id` — both are unique in the environment, and the one an app's
     * backend has to hand is its client id.
     */
    private function client(string $id): ?Client
    {
        return Client::query()
            ->where(fn ($query) => $query->whereKey($id)->orWhere('client_id', $id))
            ->first();
    }
}
