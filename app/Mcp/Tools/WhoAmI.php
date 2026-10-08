<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpCaller;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use Carbon\CarbonImmutable;
use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
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
 * missing — the answer is nearly always a scope the key was not given, or, for a person
 * signed in, a right they do not hold in the organization the token is bound to; this says
 * which without anyone opening the console.
 *
 * A person signed in at the platform root is told their workspace and role there, whether
 * they run the deployment, and the environments they may act in — the values an
 * environment tool's `environment` argument takes.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class WhoAmI extends Tool
{
    protected string $name = 'whoami';

    protected string $title = 'Who am I';

    protected string $description = 'Who this connection acts as: the credential kind and id (a management key, or a person who signed you in), the environment or workspace it is bound to, its issuer, and the scopes it holds. Signed in at the platform root, it also lists the environments you can act in — the values an environment tool\'s `environment` argument takes. Check this first when a tool you expected is not listed.';

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

        if ($principal instanceof DelegatedTokenPrincipal) {
            // A person, through a client they signed in: who they are, which organization the
            // token acts in and what they hold there, and the client — the three things that
            // decide which tools are listed, beside the scopes.
            $organization = $principal->organization();
            $body['name'] = $principal->personName();
            $body['subject'] = $principal->subjectId();
            $body['client'] = ['id' => $principal->clientId(), 'name' => $principal->clientName()];
            $body['organization'] = $organization === null ? null : ['id' => $organization->id, 'name' => $organization->name, 'role' => $organization->role->value];
            $body['scopes'] = $principal->managementScopes();
            $body['approvals'] = 'Every critical action waits for your approval on your device.';
            $body['expires_at'] = $principal->expiresAt() === null ? null : CarbonImmutable::createFromTimestamp($principal->expiresAt())->toIso8601String();
        }

        if ($principal instanceof RootPersonPrincipal) {
            // A workspace member — or an operator — signed in at the root: one connection
            // for the workspace, each environment of it they may administer, their own
            // account and, for an operator, the deployment.
            $workspace = $principal->workspace();
            $body['environment'] = null;
            $body['name'] = $principal->personName();
            $body['subject'] = $principal->subjectId();
            $body['client'] = ['id' => $principal->clientId(), 'name' => $principal->clientName()];
            $body['workspace'] = $workspace === null ? null : ['id' => $workspace->id, 'name' => $workspace->name, 'role' => $workspace->role->value];
            $body['operator'] = $principal->isOperator();
            $body['environments'] = array_map(static fn (Environment $environment): array => [
                'id' => $environment->id,
                'slug' => $environment->slug,
                'name' => $environment->name,
            ], $principal->environments());
            $body['scopes'] = $principal->managementScopes();
            $body['approvals'] = 'Every critical action waits for your approval on your device.';
            $body['expires_at'] = $principal->expiresAt() === null ? null : CarbonImmutable::createFromTimestamp($principal->expiresAt())->toIso8601String();
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
