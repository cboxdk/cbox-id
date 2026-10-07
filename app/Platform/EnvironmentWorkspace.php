<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The environment a request runs in, and the WORKSPACE that owns it — where a change to the
 * environment itself is recorded.
 *
 * The environment console has always recorded such a change (self-service sign-up switched
 * on, a custom domain verified) on the trail of the workspace whose membership opened it:
 * the environment is the workspace's, and its people read their activity there. A
 * management key has no membership, so the trail is found the other way round — from the
 * environment to its project to the project's organization — which is the same workspace by
 * construction, because a membership can only administer its own workspace's environments.
 * Both doors therefore ask here, and write the same entry to the same trail.
 */
final readonly class EnvironmentWorkspace
{
    public function __construct(
        private EnvironmentContext $context,
        private PlatformRoot $platformRoot,
        private OrganizationActivity $activity,
    ) {}

    /**
     * The environment this request runs in, with its settings — or null off any
     * environment's host. {@see CurrentEnvironment} reads only the columns a label needs.
     */
    public function environment(): ?Environment
    {
        $key = $this->context->current()?->environmentKey();

        return $key === null ? null : Environment::query()->find($key);
    }

    /** The workspace (an organization in the platform root) whose project $environmentId is in. */
    public function workspaceOf(string $environmentId): ?string
    {
        return $this->platformRoot->run(function () use ($environmentId): ?string {
            $projectId = Environment::query()->whereKey($environmentId)->value('project_id');

            if (! is_string($projectId)) {
                return null;
            }

            $workspaceId = Project::query()->whereKey($projectId)->value('organization_id');

            return is_string($workspaceId) ? $workspaceId : null;
        });
    }

    /**
     * Record a change to the environment on its workspace's trail, as $principal's act: a
     * person as the workspace member they are, a key as the service it is.
     *
     * @param  array<string, mixed>  $context
     * @return bool whether there was a workspace to record it on
     */
    public function record(Principal $principal, string $environmentId, string $action, array $context = []): bool
    {
        $workspaceId = $this->workspaceOf($environmentId);

        if ($workspaceId === null) {
            return false;
        }

        $actor = $principal->auditActor();

        $this->activity->record(
            $workspaceId,
            $action,
            $actor->id,
            targetType: 'environment',
            targetId: $environmentId,
            context: $context,
            request: request(),
            actorType: $actor->type === ActorType::Service ? ActorType::Service : ActorType::OrganizationMember,
        );

        return true;
    }
}
