<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpCaller;
use App\Platform\Actions\Approvals\ActionApprovalGate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `approval_status` — where an approval this credential asked for stands.
 *
 * A tool that answered `approval_pending` names an approval; the person approves it on their
 * phone. Poll this until it says `approved`, then call the original tool again with the same
 * arguments plus `approval_id`. Only the credential that raised an approval can see it.
 */
#[IsReadOnly]
final class ApprovalStatus extends Tool
{
    protected string $name = 'approval_status';

    protected string $title = 'Approval status';

    protected string $description = 'Where an approval you were asked to wait for stands: pending, approved, denied, expired or consumed. When it is approved, call the held tool again with the same arguments and `approval_id`.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()->description('The approval id from an `approval_pending` answer.')->required(),
        ];
    }

    public function handle(Request $request, McpCaller $caller, ActionApprovalGate $gate): ResponseFactory
    {
        $principal = $caller->principal();
        $id = $request->get('approval_id');

        if ($principal === null || ! is_string($id) || $id === '') {
            return Response::make(Response::error('An approval_id is required.'))->withStructuredContent(['error' => 'validation_failed', 'field' => 'approval_id']);
        }

        $status = $gate->status($principal, $id);

        if ($status === null) {
            return Response::make(Response::error('No approval with that id belongs to this credential.'))->withStructuredContent(['error' => 'not_found']);
        }

        return Response::structured(['approval_id' => $id, 'status' => $status->value]);
    }
}
