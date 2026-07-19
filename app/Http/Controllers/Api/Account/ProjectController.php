<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Platform\AccountApiContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account plane › projects. Lists and provisions the projects (IdP products) an
 * account owns — each its own billing anchor and environment allowance. This is what
 * lets an API-driven customer stand up a SECOND separately-billed product; environments
 * are then created under a chosen `project_id` via {@see EnvironmentController::store}.
 * Thin: it maps HTTP to the {@see AccountProvisioner} / {@see Projects} repo.
 */
final class ProjectController extends Controller
{
    public function index(AccountApiContext $context, Projects $projects): JsonResponse
    {
        $account = $context->key()?->account;

        if ($account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        return response()->json([
            'data' => $projects->forAccount($account->id)->map(fn (Project $p): array => $this->present($p))->all(),
        ]);
    }

    public function store(Request $request, AccountApiContext $context, AccountProvisioner $provisioner): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'environment_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $account = $context->key()?->account;

        if ($account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        $limit = $request->has('environment_limit') ? $request->integer('environment_limit') : null;
        $project = $provisioner->addProject($account, $request->string('name')->toString(), $limit);

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
