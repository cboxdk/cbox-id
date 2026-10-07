<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpCaller;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Who the server thinks is calling: the principal, the environment it acts in, and what it
 * may do there.
 *
 * Always listed, because it is the first thing to check when a tool an agent expected is
 * missing — the answer is nearly always a scope the key was not given, and this says so
 * without anyone opening the console.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class WhoAmI extends Tool
{
    protected string $name = 'whoami';

    protected string $title = 'Who am I';

    protected string $description = 'Who this connection acts as: the credential kind and id, the environment or workspace it is bound to, its issuer, and the scopes it holds. Check this first when a tool you expected is not listed.';

    public function handle(McpCaller $caller, EnvironmentContext $environments): ResponseFactory
    {
        $principal = $caller->principal();
        $environment = $environments->current();

        $body = [
            'kind' => $principal?->kind(),
            'id' => $principal?->id(),
            'environment' => $environment?->environmentKey(),
            'issuer' => ServerMetadata::issuer(),
            'scopes' => [],
        ];

        if ($principal instanceof EnvironmentKeyPrincipal) {
            $key = $principal->key();
            $body['name'] = $key->name;
            $body['scopes'] = $key->scopes;
            $body['expires_at'] = $key->expires_at?->toIso8601String();
        }

        if ($principal instanceof WorkspaceKeyPrincipal) {
            $key = $principal->key();
            $body['environment'] = null;
            $body['workspace'] = $key->organization_id;
            $body['name'] = $key->name;
            $body['role'] = $key->role->value;
            $body['scopes'] = $key->scopes ?? [];
            $body['expires_at'] = $key->expires_at?->toIso8601String();
        }

        return Response::structured($body);
    }
}
