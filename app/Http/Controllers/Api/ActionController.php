<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\ApiErrorRenderer;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRoutes;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Approvals\ApprovalRequired;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\EnvironmentApiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The REST door to every action: one controller, routed per action from the registry
 * ({@see ActionRoutes}).
 *
 * It does three things and no more: names the principal (the management key the
 * middleware authenticated), gathers the input (URL parameters, query and body as one
 * argument list — the same list an MCP tool call carries), and renders the outcome in the
 * management API's envelope. Validation and authorization failures are thrown on to
 * {@see ApiErrorRenderer}, which renders them the same as before actions existed.
 */
final readonly class ActionController
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionRunner $runner,
    ) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $route = $request->route();
        $name = $route?->defaults['action'] ?? null;
        $action = $this->registry->named(is_string($name) ? $name : '');
        // Asked of the container on every request, never held: the router keeps one
        // controller per route, and the context is SCOPED — once a queued job has run in
        // this process its scoped instances are forgotten, the middleware authenticates the
        // next request into a fresh one, and a context held here would still be the old,
        // cleared one: a valid key answered 401.
        $key = app(EnvironmentApiContext::class)->key() ?? abort(401);

        /** @var array<string, mixed> $body */
        $body = $request->all();
        /** @var array<string, mixed> $parameters */
        $parameters = $route?->parameters() ?? [];
        $input = [...$body, ...array_intersect_key($parameters, array_flip($action->input()->pathFields()))];

        try {
            $result = $this->runner->run(
                $action,
                new EnvironmentKeyPrincipal($key),
                $input,
                $request->headers->get('Idempotency-Key'),
                $request->headers->get('Cbox-Approval'),
            );
        } catch (ApprovalRequired $held) {
            return response()->json([
                'error' => 'approval_required',
                'message' => $held->getMessage(),
                'approval' => [
                    'id' => $held->approvalId,
                    'status' => 'pending',
                    'binding_code' => $held->bindingCode,
                    'expires_at' => $held->expiresAt->toIso8601String(),
                    'poll_url' => url('/api/v1/action-approvals/'.$held->approvalId),
                ],
            ], 202, ['Retry-After' => (string) $held->interval]);
        } catch (ActionRefused $refused) {
            return response()->json(['error' => $refused->error, 'message' => $refused->getMessage()], $refused->status);
        }

        return $this->render($result, $action->status);
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
