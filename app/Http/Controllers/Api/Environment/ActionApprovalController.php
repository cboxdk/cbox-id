<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\EnvironmentApiContext;
use App\Platform\WorkspaceApiContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/action-approvals/{id}` — where an approval this key asked for stands, so the
 * caller knows when to repeat its request with `Cbox-Approval: {id}`.
 *
 * No scope: any key may poll the approvals IT raised, and only those — another key's id is
 * a 404, the same answer as an id that never existed.
 */
final class ActionApprovalController extends Controller
{
    public function show(string $id, EnvironmentApiContext $context, ActionApprovalGate $gate): JsonResponse
    {
        return $this->answer($id, new EnvironmentKeyPrincipal($context->key() ?? abort(401)), $gate);
    }

    /** The same question from a workspace key, at `/api/v1/workspace/action-approvals/{id}`. */
    public function showForWorkspace(string $id, WorkspaceApiContext $context, ActionApprovalGate $gate): JsonResponse
    {
        return $this->answer($id, new WorkspaceKeyPrincipal($context->key() ?? abort(401)), $gate);
    }

    private function answer(string $id, Principal $principal, ActionApprovalGate $gate): JsonResponse
    {
        $status = $gate->status($principal, $id);

        if ($status === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Approval not found.'], 404);
        }

        return response()->json(['data' => ['id' => $id, 'status' => $status->value]]);
    }
}
