<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Principal\Principal;
use App\Platform\DelegatedApiContext;
use App\Platform\EnvironmentApiContext;
use App\Platform\WorkspaceApiContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/action-approvals/{id}` — where an approval this key asked for stands, so the
 * caller knows when to repeat its request with `Cbox-Approval: {id}`.
 *
 * No scope: any key — or a person, through the token they signed an agent in with — may
 * poll the approvals IT raised, and only those; another credential's id is a 404, the same
 * answer as an id that never existed. A person signed in at the platform root polls the
 * ones they raised in any environment of their workspace from the root, unbound.
 */
final class ActionApprovalController extends Controller
{
    public function show(string $id, EnvironmentApiContext $context, ActionApprovalGate $gate): JsonResponse
    {
        return $this->answer($id, $context->principal() ?? abort(401), $gate);
    }

    /**
     * The same question from a workspace key — or a member's root token — at
     * `/api/v1/workspace/action-approvals/{id}`.
     */
    public function showForWorkspace(string $id, WorkspaceApiContext $context, ActionApprovalGate $gate): JsonResponse
    {
        return $this->answer($id, $context->principal() ?? abort(401), $gate);
    }

    /**
     * The same question from a person's delegated token on the planes no key reaches — at
     * `/api/v1/me/action-approvals/{id}` and `/api/v1/platform/action-approvals/{id}`, the
     * `poll_url` a held account or operator action names.
     */
    public function showForPerson(string $id, DelegatedApiContext $context, ActionApprovalGate $gate): JsonResponse
    {
        return $this->answer($id, $context->principal() ?? abort(401), $gate);
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
