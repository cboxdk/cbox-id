<?php

declare(strict_types=1);

namespace App\Platform\Apps;

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE ENVIRONMENTS an app may be copied into from here: the other environments of THIS
 * environment's project that the person acting may administer.
 *
 * Copying an app mints a live credential in the target, so the target is an authorization
 * question, not a list. Three conditions, each of which alone would be a hole:
 *
 *  - THE SAME PROJECT. Staging and production of one product are what "promote" means.
 *    Another project of the same workspace is another product with its own plan and its
 *    own people, and an app is not something to carry between them in one click.
 *  - AN ENVIRONMENT THIS PERSON MAY REACH. A workspace member can be restricted to some of
 *    its environments ({@see Memberships::accessibleEnvironmentIds()}); administering this
 *    one says nothing about the others, and a copy is a write in the other one.
 *  - ONE THAT IS SERVING. A suspended environment's apps cannot sign anyone in.
 *
 * The same predicate serves the list the page offers and the write that uses it, so a
 * crafted target id meets exactly the refusal an honest one would.
 */
final readonly class CopyTargets
{
    public function __construct(
        private EnvironmentAdminAuth $admin,
        private EnvironmentContext $context,
        private Memberships $memberships,
        private PlatformRoot $root,
    ) {}

    /**
     * The eligible environments, by name.
     *
     * @return list<Environment>
     */
    public function all(): array
    {
        $here = $this->context->current();
        $membership = $this->admin->membership();

        if ($here === null || $membership === null) {
            return [];
        }

        $projectId = Environment::query()->whereKey($here->environmentKey())->value('project_id');

        if (! is_string($projectId) || $projectId === '') {
            return [];
        }

        // Read in the PLATFORM ROOT, like every other question about a workspace member:
        // the membership lives there, and the ambient scope here is the environment being
        // administered.
        $reachable = $this->root->run(fn (): array => $this->memberships->accessibleEnvironmentIds(
            $membership->organization_id,
            $membership->user_id,
        )) ?? [];

        return array_values(Environment::query()
            ->where('project_id', $projectId)
            ->whereKeyNot($here->environmentKey())
            ->whereIn('id', $reachable)
            ->where('status', EnvironmentStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /** One eligible environment by id, or null — never an environment the list would not offer. */
    public function find(string $id): ?Environment
    {
        foreach ($this->all() as $environment) {
            if ($environment->id === $id) {
                return $environment;
            }
        }

        return null;
    }
}
