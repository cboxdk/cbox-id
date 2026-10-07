<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ActionTool;
use App\Mcp\IdServer;
use App\Mcp\McpCaller;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A compact index of the actions this connection may run: tool name, one-line summary,
 * scope and danger — without the input schemas.
 *
 * The full tool list carries every schema, which is most of its size; this is the
 * cheap overview an agent can read to plan, and the place it learns an action is
 * destructive before it picks the tool. With tool search on ({@see IdServer}) it
 * is also the plain list next to `search_tools`'s ranked one.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListActions extends Tool
{
    protected string $name = 'list_actions';

    protected string $title = 'List actions';

    protected string $description = 'A compact list of every action this connection may run: tool name, summary, required scope and danger (read, write, destructive, critical). Use it to plan; call the named tool to act.';

    public function handle(McpCaller $caller, ActionRegistry $registry): ResponseFactory
    {
        $principal = $caller->principal();
        $actions = [];

        foreach ($registry->forPlane(ActionPlane::Environment) as $action) {
            if (! ActionTool::permits($principal, $action)) {
                continue;
            }

            $actions[] = [
                'tool' => $action->toolName(),
                'action' => $action->name,
                'summary' => $action->summary,
                'scope' => $action->scope,
                'danger' => $action->danger->value,
            ];
        }

        return Response::structured(['actions' => $actions]);
    }
}
