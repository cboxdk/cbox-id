<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\EnvironmentApiContext;
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
        $key = $context->key() ?? abort(401);
        $status = $gate->status(new EnvironmentKeyPrincipal($key), $id);

        if ($status === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Approval not found.'], 404);
        }

        return response()->json(['data' => ['id' => $id, 'status' => $status->value]]);
    }
}
