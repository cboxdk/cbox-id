<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Platform\AccountApiContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\Projects;
use Illuminate\Http\JsonResponse;

/**
 * Account plane › the account itself. Returns the account and its per-project plans —
 * the programmatic view of the workspace console's billing summary. The plan/allowance
 * anchors on the PROJECT (one account can own several independently-billed products),
 * so the plan block is a list of projects with each one's own environment allowance —
 * never a single account-level number, which would misreport a multi-project account.
 */
final class AccountController extends Controller
{
    public function show(AccountApiContext $context, Projects $projects): JsonResponse
    {
        $account = $context->key()?->account;

        if ($account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        $payload = [
            'id' => $account->id,
            'name' => $account->name,
            'status' => $account->status,
        ];

        // The plan/usage block is billing data — included only for a key whose role
        // may read billing (a Developer/CI key gets the account identity, not the plan).
        if ($context->role()?->canReadBilling() ?? false) {
            $payload['projects'] = $projects->forAccount($account->id)->map(fn ($project): array => [
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
