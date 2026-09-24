<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * WHERE A WORKSPACE MEMBER LANDS after signing in: where they work.
 *
 * A customer signing in used to land on an overview of their workspace's own organization
 * in Cbox's environment — sign-in counts for a team of four, a setup checklist for roles
 * and apps they would never configure there — while the product they came to work on was
 * two clicks and a host away. With one environment to work in, that is where they go,
 * through the same signed handoff Projects › Open uses. With several (or none they may
 * open), Projects is the home: it lists every environment with an "Open console" beside it.
 *
 * Only for the sign-in landing. The environment console's way back to the workspace links
 * to Projects, not here, or it would bounce straight back into the environment.
 */
final readonly class WorkspaceLanding
{
    public function __construct(
        private ConsoleScope $scope,
        private Memberships $members,
        private PlatformRoot $platformRoot,
    ) {}

    public function url(): string
    {
        $environment = $this->onlyEnvironment();

        return $environment === null
            ? route('projects')
            : route('environment.open', $environment);
    }

    /**
     * The one environment this person may open, or null when there is not exactly one.
     *
     * The same two gates the handoff itself applies — the capability to administer
     * environments, and reach to this one — so the landing never mints a handoff that
     * the door would refuse. A suspended environment, or one under a suspended project,
     * is not somewhere anybody can work, so it does not count towards "exactly one".
     */
    private function onlyEnvironment(): ?string
    {
        if ($this->scope->capabilities()?->canManageEnvironments() !== true) {
            return null;
        }

        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        if ($organizationId === null || $actorId === '') {
            return null;
        }

        $reachable = $this->platformRoot->run(
            fn (): array => $this->members->accessibleEnvironmentIds($organizationId, $actorId),
        ) ?? [];

        if ($reachable === []) {
            return null;
        }

        /** @var list<string> $open */
        $open = Environment::query()
            ->whereIn('id', $reachable)
            ->where('status', EnvironmentStatus::Active->value)
            ->whereIn('project_id', Project::query()
                ->where('organization_id', $organizationId)
                ->where('status', ProjectStatus::Active->value)
                ->select('id'))
            ->limit(2)
            ->pluck('id')
            ->all();

        return count($open) === 1 ? $open[0] : null;
    }
}
