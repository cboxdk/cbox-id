<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Controller;
use App\Platform\OrganizationApiContext;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization plane › environments. Lists and provisions the environments an organization
 * owns. Thin: it maps HTTP to the {@see TenantProvisioner} and the Environment
 * model, nothing more.
 */
final class EnvironmentController extends Controller
{
    public function index(Request $request, OrganizationApiContext $context): JsonResponse
    {
        $limit = min(100, max(1, $request->integer('limit', 50)));
        $page = max(1, $request->integer('page', 1));

        // `limit + 1` to learn whether there is a next page without a second COUNT — the
        // extra row is read and dropped.
        $environments = Environment::query()
            ->whereIn('project_id', Project::query()->where('organization_id', (string) $context->organizationId())->pluck('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->skip(($page - 1) * $limit)
            ->limit($limit + 1)
            ->get();

        // `has_more` WITH A WAY TO ACT ON IT. The envelope advertised a next-cursor signal
        // and carried no cursor and no page parameter, so a caller told there were more
        // environments had no means of asking for them. An organization's environments are
        // plan-bounded, so page numbers are enough — and they match the member list next
        // door rather than inventing a second idiom.
        $hasMore = $environments->count() > $limit;

        return response()->json([
            'data' => $environments->take($limit)->map(fn (Environment $e): array => $this->present($e))->values()->all(),
            'meta' => array_filter([
                'limit' => $limit,
                'page' => $page,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
            ], static fn ($value): bool => $value !== null),
        ]);
    }

    public function store(Request $request, OrganizationApiContext $context, TenantProvisioner $provisioner, OrganizationProjects $projects): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::enum(EnvironmentType::class)],
            'project_id' => ['sometimes', 'string'],
        ]);

        $organizationId = $context->organizationId();

        if ($organizationId === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        // Environments belong to a project (the billing anchor). Target the requested
        // project, or fall back to the organization's first project for back-compat with
        // callers that predate the project layer.
        $projectId = $request->string('project_id')->toString();
        $project = $projectId !== ''
            ? $projects->forOrganization($organizationId)->firstWhere('id', $projectId)
            : $projects->forOrganization($organizationId)->first();

        if ($project === null || $project->organization_id !== $organizationId) {
            return response()->json(['error' => 'not_found', 'message' => 'Project not found.'], 404);
        }

        try {
            $environment = $provisioner->addEnvironment(
                $project,
                $request->string('name')->toString(),
                type: $request->enum('type', EnvironmentType::class) ?? EnvironmentType::Production,
            );
        } catch (EnvironmentLimitReached $e) {
            return response()->json([
                'error' => 'environment_limit_reached',
                'message' => $e->getMessage(),
            ], 422);
        }

        // Single resources use the same {data:…} envelope as lists.
        return response()->json(['data' => $this->present($environment)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Environment $environment): array
    {
        $base = config('cbox-id.environments.base_domains', []);
        $first = is_array($base) && isset($base[0]) && is_string($base[0]) ? $base[0] : null;
        $baseDomain = $first ?? request()->getHost();

        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'type' => $environment->type->value,
            'status' => $environment->status,
            // Which project (billing anchor) this environment belongs to.
            'project_id' => $environment->getAttribute('project_id'),
            'domain' => $environment->domain,
            // Only a VERIFIED domain may stand as the issuer — that is exactly the rule
            // EnvironmentIssuerResolver enforces, and reporting a different one here
            // would tell an integrator to configure an issuer the server will not assert.
            'issuer' => 'https://'.($environment->domain_verified_at !== null && $environment->domain !== null
                ? $environment->domain
                : $environment->slug.'.'.$baseDomain),
        ];
    }
}
