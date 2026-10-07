<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Environments;

use App\Actions\Workspace\InWorkspace;
use App\Actions\Workspace\Keys\EnvironmentKeyIssuer;
use App\Http\Resources\Workspace\EnvironmentResource;
use App\Http\Resources\Workspace\KeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\TenantProvisioner;

/**
 * Add an environment to one of the workspace's projects — and, with `initial_key`, mint
 * its first management key in the same call.
 *
 * THE INITIAL KEY IS WHAT MAKES ONE WORKSPACE KEY ENOUGH. Without it an agent holding a
 * workspace key could stand up an environment and then not use it: the environment's API
 * is served on the environment's own host and takes only that environment's key, and the
 * only way to get one was a person in the console. With it, the answer carries the new
 * environment and a key for it, once (`initial_key.token`, never kept for a replay), and
 * the agent goes on to register its apps and APIs there. The key is minted the way every
 * workspace-minted key is ({@see EnvironmentKeyIssuer}): an offered scope set, recorded
 * provenance, and a line on the workspace's log.
 *
 * Critical, because it can mint a credential; without `initial_key` it is an ordinary
 * environment creation, and approval policies can tell the two apart by the input.
 *
 * `project_id` left out means the workspace's first project, for callers that predate the
 * project layer. Another workspace's project is not found.
 */
#[AsAction(
    name: 'environments.create',
    summary: 'Create an environment under one of the workspace\'s projects; with `initial_key`, also mint its first management key, returned once as `initial_key.token`.',
    scope: 'environments:write',
    danger: Danger::Critical,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/environments'],
    status: 201,
    consoleRoutes: ['projects.environments.store'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'CreatedEnvironment',
    tag: 'Environments',
    redact: ['initial_key.token'],
)]
final readonly class CreateEnvironment implements Action
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private OrganizationProjects $projects,
        private EnvironmentKeyIssuer $keys,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
            Field::string('type')->oneOf(array_map(static fn (EnvironmentType $type): string => $type->value, EnvironmentType::cases()))->describe('Default `production`.'),
            Field::string('project_id')->max(64)->describe('The project (billing anchor) to create it under. Defaults to the workspace\'s first project.'),
            Field::object('initial_key', [
                Field::string('name')->required()->max(120),
                Field::list('scopes', Field::string('scope')->max(64))->required()->min(1)->max(64)->describe('The environment-key scopes it carries, for example `apis:write`.'),
                Field::string('expires_at')->nullable()->format('date-time')->describe('When it stops working. Left out, it does not expire.'),
            ])->nullable()->describe('Mint the environment\'s first management key in the same call. Its value is returned once, as `initial_key.token`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $project = $this->project($workspaceId, $context->nullableString('project_id'));

        InWorkspace::assertUnscoped($context->principal, $workspaceId);

        $name = trim($context->string('name'));
        $type = EnvironmentType::tryFrom($context->string('type')) ?? EnvironmentType::Production;
        $wantsKey = is_array($context->input['initial_key'] ?? null);

        // Asked BEFORE anything is provisioned, so a malformed key request is a refusal and
        // not an environment with no key and an error beside it.
        $expiresAt = $wantsKey ? EnvironmentKeyIssuer::expiry($context, 'initial_key.expires_at') : null;

        try {
            $environment = $this->provisioner->addEnvironment($project, $name, type: $type);
        } catch (EnvironmentLimitReached $refused) {
            throw ActionRefused::because('environment_limit_reached', $refused->getMessage(), 'name');
        }

        InWorkspace::record($context->principal, $workspaceId, 'organization.environment_created', 'environment', $environment->id, [
            'name' => $name,
            'type' => $type->value,
        ]);

        $payload = EnvironmentResource::from($environment);

        if ($wantsKey) {
            $asked = $context->array('initial_key');
            $keyName = is_string($asked['name'] ?? null) ? trim($asked['name']) : '';
            $scopes = is_array($asked['scopes'] ?? null) ? $asked['scopes'] : [];

            $issued = $this->keys->issue(
                $context->principal,
                $workspaceId,
                $environment,
                $keyName === '' ? $name.' key' : $keyName,
                array_values(array_filter($scopes, 'is_string')),
                $expiresAt,
                'initial_key.scopes',
            );

            $payload['initial_key'] = KeyResource::environment($issued->key, $issued->plaintext);
        }

        return ActionResult::item($environment, $payload);
    }

    /** @throws ActionRefused */
    private function project(string $workspaceId, ?string $projectId): Project
    {
        $owned = $this->projects->forOrganization($workspaceId);
        $project = $projectId !== null ? $owned->firstWhere('id', $projectId) : $owned->first();

        return $project instanceof Project && $project->organization_id === $workspaceId
            ? $project
            : throw ActionRefused::notFound('project');
    }
}
