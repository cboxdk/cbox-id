<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Controller;
use App\Platform\OrganizationApiContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account plane › projects. Lists and provisions the projects (IdP products) an
 * organization owns — each its own billing anchor and environment allowance. This is what
 * lets an API-driven customer stand up a SECOND separately-billed product; environments
 * are then created under a chosen `project_id` via {@see EnvironmentController::store}.
 * Thin: it maps HTTP to the {@see TenantProvisioner} / {@see Projects} repo.
 */
final class ProjectController extends Controller
{
    public function index(OrganizationApiContext $context, OrganizationProjects $projects): JsonResponse
    {
        $organizationId = $context->organizationId();

        if ($organizationId === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
        }

        return response()->json([
            'data' => $projects->forOrganization($organizationId)->map(fn (Project $p): array => $this->present($p))->all(),
        ]);
    }

    public function store(
        Request $request,
        OrganizationApiContext $context,
        TenantProvisioner $provisioner,
        Organizations $organizations,
        PlatformRoot $platformRoot,
    ): JsonResponse {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'environment_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $organizationId = $context->organizationId();

        // IN THE PLATFORM ROOT: `organizations` is environment-owned and this plane pins no
        // environment, so an unscoped read finds nothing and a valid key 404s for its own
        // organization. addProject() re-reads it under a lock anyway; this is the model the
        // signature asks for.
        $organization = $organizationId === null
            ? null
            : $platformRoot->run(fn () => $organizations->find($organizationId));

        if ($organization === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
        }

        $limit = $request->has('environment_limit') ? $request->integer('environment_limit') : null;
        $project = $provisioner->addProject($organization, $request->string('name')->toString(), $limit);

        return response()->json(['data' => $this->present($project)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'environment_limit' => $project->environment_limit,
            'environments_used' => Environment::query()->where('project_id', $project->id)->count(),
        ];
    }
}
