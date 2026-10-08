<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mcp\ActionTool;
use App\Platform\Actions\ActionRegistry;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ONE AGENT, EITHER DOOR: the REST API and the MCP server, driven through the same three
 * verbs so a scenario is written once and run over both.
 *
 * The plan's acceptance criterion for "agents can do everything" is a scenario that passes
 * over REST AND over MCP with identical assertions. Two copies of each scenario would drift
 * the first time one was edited, so the scenario speaks to a door, and the door speaks its
 * own protocol:
 *
 * - **REST** resolves the action's method and path from the {@see ActionRegistry} — the
 *   same list the routes are generated from — substitutes the path fields, sends the rest as
 *   the body (or the query, on a GET), and sends an `Idempotency-Key` on every write, as the
 *   CLI does. A held call is repeated with `Cbox-Approval` and the SAME key.
 * - **MCP** calls the action's tool ({@see ActionTool}, named by `toolName()`) with the
 *   input as arguments, `idempotency_key` on a write, and `approval_id` on the repeat.
 *
 * Every answer comes back in one shape, so the scenario never asks which door it is on:
 *
 *     status    'ok' | 'approval_required' | 'refused'
 *     data      the action's payload (REST `data`, MCP `structuredContent.data`)
 *     error     the stable error code of a refusal (`unauthorized`, `forbidden`, …)
 *     approval  {id, binding_code, expires_at} while a person's approval is awaited
 *
 * plus `request`, what was sent, so {@see self::approve()} can repeat it exactly.
 *
 * Every request carries its own headers rather than the test case's sticky ones: a test
 * holds several credentials at once (the workspace key, the key it minted, the key THAT
 * minted), and a header left over from the previous call would be a bug in the harness
 * that looks like one in the product.
 */
final readonly class AgentDoor
{
    public const string REST = 'rest';

    public const string MCP = 'mcp';

    private function __construct(public string $via) {}

    public static function rest(): self
    {
        return new self(self::REST);
    }

    public static function mcp(): self
    {
        return new self(self::MCP);
    }

    /**
     * Run $action with $input as the holder of $token.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $environment  From the platform root: the environment of the
     *                                    workspace to act in (`Cbox-Environment` / `environment`).
     * @return array{status: string, data: mixed, error: ?string, approval: ?array<string, mixed>, request: array<string, mixed>}
     */
    public function call(string $token, string $action, array $input = [], ?string $environment = null, ?string $idempotencyKey = null, ?string $approvalId = null): array
    {
        $definition = app(ActionRegistry::class)->named($action);
        $idempotencyKey ??= $definition->danger->writes() ? 'agent-'.Str::lower((string) Str::ulid()) : null;

        $request = [
            'token' => $token,
            'action' => $action,
            'input' => $input,
            'environment' => $environment,
            'idempotency_key' => $idempotencyKey,
        ];

        $answer = $this->via === self::REST
            ? $this->overRest($request, $approvalId)
            : $this->overMcp($request, $approvalId);

        return [...$answer, 'request' => $request];
    }

    /**
     * The environments this credential may act in — the values `environment` takes.
     *
     * Over MCP that is what `whoami` says, and what an agent is told to ask first. REST has
     * no `whoami`; the same list is the workspace's `environments.list`, which a person's
     * root token reaches as a member of the workspace.
     *
     * @return list<array{id: string, slug: string, name: string}>
     */
    public function environments(string $token): array
    {
        if ($this->via === self::REST) {
            $listed = $this->call($token, 'environments.list');

            expect($listed['status'])->toBe('ok');

            return array_values(array_map(static fn (array $environment): array => [
                'id' => (string) $environment['id'],
                'slug' => (string) $environment['slug'],
                'name' => (string) $environment['name'],
            ], (array) $listed['data']));
        }

        $response = test()->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami', 'arguments' => (object) []],
        ], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json, text/event-stream',
        ])->assertOk();

        /** @var list<array{id: string, slug: string, name: string}> $environments */
        $environments = (array) $response->json('result.structuredContent.environments');

        return $environments;
    }

    /**
     * $person approves the call $held is waiting on — on their device, which is the
     * platform root's backchannel authentication — and the call is repeated with the
     * approval, exactly as it was first sent.
     *
     * @param  array{status: string, approval: ?array<string, mixed>, request: array<string, mixed>}  $held
     * @return array{status: string, data: mixed, error: ?string, approval: ?array<string, mixed>, request: array<string, mixed>}
     */
    public function approve(array $held, string $person): array
    {
        if ($held['status'] !== 'approval_required' || ! is_string($held['approval']['id'] ?? null)) {
            throw new InvalidArgumentException('That call is not waiting for an approval: '.json_encode($held));
        }

        $approvalId = $held['approval']['id'];
        $approved = app(PlatformRoot::class)->run(static fn (): bool => app(BackchannelAuthentication::class)->approve($approvalId, $person));

        if ($approved !== true) {
            throw new InvalidArgumentException("{$person} could not approve {$approvalId}.");
        }

        $request = $held['request'];

        return $this->call(
            (string) $request['token'],
            (string) $request['action'],
            (array) $request['input'],
            is_string($request['environment']) ? $request['environment'] : null,
            is_string($request['idempotency_key']) ? $request['idempotency_key'] : null,
            $approvalId,
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{status: string, data: mixed, error: ?string, approval: ?array<string, mixed>}
     */
    private function overRest(array $request, ?string $approvalId): array
    {
        $definition = app(ActionRegistry::class)->named((string) $request['action']);
        $input = (array) $request['input'];

        // The path fields go into the URL; whatever is left is the body, or the query.
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $match) use (&$input): string {
            $value = $input[$match[1]] ?? throw new InvalidArgumentException("The path field {$match[1]} is missing.");
            unset($input[$match[1]]);

            return rawurlencode((string) $value);
        }, '/api/v1'.$definition->documentedPath());

        $headers = array_filter([
            'Authorization' => 'Bearer '.$request['token'],
            'Accept' => 'application/json',
            'Idempotency-Key' => $request['idempotency_key'],
            'Cbox-Approval' => $approvalId,
            'Cbox-Environment' => $request['environment'],
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $response = $definition->method === 'GET'
            ? test()->getJson($path.($input === [] ? '' : '?'.http_build_query($input)), $headers)
            : test()->json($definition->method, $path, $input, $headers);

        return self::fromRest($response);
    }

    /**
     * @return array{status: string, data: mixed, error: ?string, approval: ?array<string, mixed>}
     */
    private static function fromRest(TestResponse $response): array
    {
        $status = $response->getStatusCode();
        $body = $status === 204 ? [] : (array) $response->json();

        if ($status === 202 && ($body['error'] ?? null) === 'approval_required') {
            return ['status' => 'approval_required', 'data' => null, 'error' => null, 'approval' => (array) $body['approval']];
        }

        if ($status >= 200 && $status < 300) {
            return ['status' => 'ok', 'data' => $body['data'] ?? null, 'error' => null, 'approval' => null];
        }

        return ['status' => 'refused', 'data' => null, 'error' => is_string($body['error'] ?? null) ? $body['error'] : "http_{$status}", 'approval' => null];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{status: string, data: mixed, error: ?string, approval: ?array<string, mixed>}
     */
    private function overMcp(array $request, ?string $approvalId): array
    {
        $definition = app(ActionRegistry::class)->named((string) $request['action']);

        $arguments = array_filter([
            ...(array) $request['input'],
            ActionTool::ENVIRONMENT => $request['environment'],
            ActionTool::IDEMPOTENCY_KEY => $request['idempotency_key'],
            ActionTool::APPROVAL_ID => $approvalId,
        ], static fn (mixed $value): bool => $value !== null);

        $response = test()->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $definition->toolName(), 'arguments' => (object) $arguments],
        ], [
            'Authorization' => 'Bearer '.$request['token'],
            'Accept' => 'application/json, text/event-stream',
        ]);

        // A credential the server does not take is refused before any tool is looked at.
        if ($response->getStatusCode() === 401) {
            return ['status' => 'refused', 'data' => null, 'error' => (string) ($response->json('error') ?? 'unauthorized'), 'approval' => null];
        }

        if ($response->baseResponse instanceof StreamedResponse) {
            preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $lines);
            $message = (array) json_decode((string) end($lines[1]), true);
        } else {
            $message = (array) $response->json();
        }

        // A tool this credential may not run is not listed to it ({@see ActionTool::permits()},
        // the principal's own authorize — the check the REST door answers 403 with), and
        // calling it anyway is MCP's protocol error `Tool [x] not found`, not a tool result.
        // The name came from the registry, so "not found" can only mean "not yours": the
        // REST door's `forbidden`.
        if (isset($message['error'])) {
            $rpc = (array) $message['error'];

            expect($rpc['message'] ?? null)->toBe("Tool [{$definition->toolName()}] not found.");

            return ['status' => 'refused', 'data' => null, 'error' => 'forbidden', 'approval' => null];
        }

        $response->assertOk();

        $result = (array) ($message['result'] ?? []);
        $content = (array) ($result['structuredContent'] ?? []);

        if (($result['isError'] ?? false) === true) {
            return ['status' => 'refused', 'data' => null, 'error' => is_string($content['error'] ?? null) ? $content['error'] : 'error', 'approval' => null];
        }

        if (($content['status'] ?? null) === 'approval_pending') {
            return ['status' => 'approval_required', 'data' => null, 'error' => null, 'approval' => (array) $content['approval']];
        }

        return ['status' => 'ok', 'data' => $content['data'] ?? null, 'error' => null, 'approval' => null];
    }
}
