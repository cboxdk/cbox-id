<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\ApiErrorRenderer;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRoutes;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\EnvironmentApiContext;
use App\Platform\WorkspaceApiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The REST door to every action: one controller, routed per action from the registry
 * ({@see ActionRoutes}).
 *
 * It does three things and no more: names the principal (the key the plane's middleware
 * authenticated — an environment key on an environment's host, a workspace key on the
 * workspace plane), gathers the input (URL parameters, query and body as one argument
 * list — the same list an MCP tool call carries), and renders the outcome in the
 * management API's envelope. Validation and authorization failures are thrown on to
 * {@see ApiErrorRenderer}, which renders them the same as before actions existed.
 */
final readonly class ActionController
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionRunner $runner,
        private EnvironmentApiContext $environment,
        private WorkspaceApiContext $workspace,
    ) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $route = $request->route();
        $name = $route?->defaults['action'] ?? null;
        $action = $this->registry->named(is_string($name) ? $name : '');

        /** @var array<string, mixed> $body */
        $body = $request->all();
        /** @var array<string, mixed> $parameters */
        $parameters = $route?->parameters() ?? [];
        $input = [...$body, ...array_intersect_key($parameters, array_flip($action->input()->pathFields()))];

        try {
            $result = $this->runner->run(
                $action,
                $this->principal($action),
                $input,
                $request->headers->get('Idempotency-Key'),
            );
        } catch (ActionRefused $refused) {
            return response()->json(['error' => $refused->error, 'message' => $refused->getMessage()], $refused->status);
        }

        return $this->render($result, $action->status);
    }

    /**
     * The key the action's OWN plane authenticated. Chosen by the action rather than by
     * whichever context happens to be filled, so an action can only ever run as the
     * credential its plane accepts — an environment action never as a workspace key.
     */
    private function principal(ActionDefinition $action): Principal
    {
        return match ($action->plane) {
            ActionPlane::Environment => new EnvironmentKeyPrincipal($this->environment->key() ?? abort(401)),
            ActionPlane::Workspace => new WorkspaceKeyPrincipal($this->workspace->key() ?? abort(401)),
        };
    }

    private function render(ActionResult $result, int $status): JsonResponse|Response
    {
        $status = $result->status ?? $status;
        $headers = $result->replayed ? ['Idempotent-Replayed' => 'true'] : [];

        if ($result->payload === null) {
            return response()->noContent(204, $headers);
        }

        $body = ['data' => $result->payload];

        if ($result->meta !== null) {
            $body['meta'] = $result->meta;
        }

        return response()->json($body, $status, $headers);
    }
}
