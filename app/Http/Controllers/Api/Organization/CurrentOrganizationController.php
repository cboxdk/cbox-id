<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Controller;
use App\Platform\OrganizationApiContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;

/**
 * Organization plane › the organization this key belongs to.
 *
 * Returns the organization and its per-project plans — the programmatic view of the
 * console's Billing page. The plan/allowance anchors on the PROJECT (one organization can
 * own several independently-billed products), so the plan block is a list of projects each
 * with its own environment allowance — never a single organization-level number, which
 * would misreport a customer running more than one product.
 *
 * NAMED `Current`, not `Organization`, because the environment plane already has an
 * `OrganizationController` and it means something else entirely: that one manages a
 * TENANT's own end-user organizations. This one is "the organization behind this key", and
 * two classes called the same thing on two planes is exactly the ambiguity the account
 * plane's disappearance was supposed to remove.
 */
final class CurrentOrganizationController extends Controller
{
    public function show(
        OrganizationApiContext $context,
        OrganizationProjects $projects,
        Organizations $organizations,
        PlatformRoot $platformRoot,
    ): JsonResponse {
        $organizationId = $context->organizationId();

        // IN THE PLATFORM ROOT. `organizations` is environment-owned, and this plane
        // resolves no environment by design — it operates above every environment the
        // organization owns — so without pinning the scope the read finds nothing and a
        // valid key gets a 404 for its own organization.
        $organization = $organizationId === null
            ? null
            : $platformRoot->run(fn () => $organizations->find($organizationId));

        if ($organization === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
        }

        $payload = [
            'id' => $organization->id,
            'name' => $organization->name,
            'status' => $organization->status,
        ];

        // The plan/usage block is billing data — included only for a key whose role may
        // read billing. A Developer/CI key gets the organization's identity, not its plan.
        if ($context->role()?->canReadBilling() ?? false) {
            $payload['projects'] = $projects->forOrganization($organization->id)->map(fn ($project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'environment_limit' => $project->environment_limit,
                'environments_used' => Environment::query()->where('project_id', $project->id)->count(),
            ])->all();
        }

        return response()->json(['data' => $payload]);
    }
}
