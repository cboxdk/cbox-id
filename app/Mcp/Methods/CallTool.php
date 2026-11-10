<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\McpCaller;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\RenamedActions;
use App\Platform\Deprecations\RetiredNameUse;
use Generator;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Methods\CallTool as BaseCallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `tools/call`, also by a tool name v3 retired ({@see RenamedActions}).
 *
 * An MCP tool is named after its action, so renaming `workspace.get` to `organization.get`
 * renamed the tool `workspace_get` to `organization_get` — and an agent's saved prompt,
 * allow-list or script that calls the old name would get "tool not found" the moment v3
 * deploys. Until v3.1 the old name is called as the new one: not listed in `tools/list`
 * (an agent that re-reads the list learns the new name), counted on every use
 * ({@see RetiredNameUse}) so the alias goes only after seven days of nobody calling it.
 * Everything else — who may run it, the approval, the audit trail — is the new tool's.
 */
final class CallTool extends BaseCallTool
{
    /** @var array<string, string>|null retired tool name => current tool name */
    private static ?array $aliases = null;

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->params['name'] ?? null;
        $current = is_string($name) ? (self::aliases()[$name] ?? null) : null;

        if ($current !== null && is_string($name)) {
            $principal = Container::getInstance()->make(McpCaller::class)->principal();
            RetiredNameUse::record('mcp_tool', $name, $current, [
                'principal' => $principal === null ? null : $principal->kind().':'.$principal->id(),
            ]);

            $request = new JsonRpcRequest($request->id, $request->method, ['name' => $current] + $request->params);
        }

        return parent::handle($request, $context);
    }

    /** @return array<string, string> */
    public static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        $aliases = [];
        foreach (RenamedActions::MAP as $old => $new) {
            $aliases[self::toolName($old)] = self::toolName($new);
        }

        return self::$aliases = $aliases;
    }

    /** The tool an action name is served as ({@see ActionDefinition::toolName()}). */
    private static function toolName(string $action): string
    {
        return str_replace(['.', '-'], '_', $action);
    }
}
