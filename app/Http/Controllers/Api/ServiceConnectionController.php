<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\ServiceConnection;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceConnectionController
{
    public function __construct(private readonly Connections $connections) {}

    public function store(Request $request, string $service): JsonResponse
    {
        return $this->response($this->present($this->connections->connect($this->caller($request), $service)));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->response(['data' => array_map($this->present(...), $this->connections->listing($this->caller($request)))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->connections->revoke($this->caller($request), $id);

        return $this->response(['revoked' => true]);
    }

    public function token(Request $request, string $id): JsonResponse
    {
        $token = $this->connections->lease($this->caller($request), $id);

        return $this->response(['access_token' => $token->token, 'token_type' => $token->tokenType, 'expires_in' => $token->expiresIn, 'scope' => implode(' ', $token->scopes), 'audience' => $token->audience]);
    }

    public function inspect(Request $request, string $id): JsonResponse
    {
        $connection = $this->connections->inspect($this->caller($request), $id);

        return $this->response($connection === null ? ['active' => false] : [
            'active' => true, ...$this->present($connection), 'service_client_id' => $connection->service_client_id,
        ]);
    }

    private function caller(Request $request): Introspection
    {
        $token = $request->attributes->get('cbox_token');
        abort_unless($token instanceof Introspection && $token->active, 401);

        return $token;
    }

    /** @return array<string, mixed> */
    private function present(ServiceConnection $connection): array
    {
        return ['id' => $connection->id, 'organization_id' => $connection->organization_id, 'service' => $connection->service,
            'consumer_client_id' => $connection->consumer_client_id, 'audience' => $connection->audience,
            'scopes' => $connection->scopes, 'revoked_at' => $connection->revoked_at?->toIso8601String()];
    }

    /** @param array<string, mixed> $body */
    private function response(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
