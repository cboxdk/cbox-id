<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Http\Controllers\Api\ActionController;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Approvals\ApprovalRequired;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\Principal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The MCP door to one action: a tool derived entirely from its {@see ActionDefinition}.
 *
 * There is one instance per action in the registry, built by {@see IdServer}, and no tool
 * class per action — the same reason there is one REST controller ({@see ActionController}):
 * an action declares its name, scope, danger and input once, and a door that needed its own
 * file per action would be a door that falls behind.
 *
 * What it derives, and from what:
 *
 * - **name** is the action's {@see ActionDefinition::toolName()} (`apis.create` →
 *   `apis_create`), so the registry is the tool list.
 * - **description** is the summary an agent reads to choose, plus the scope it needs and
 *   its {@see Danger}, because an agent deciding whether to ask a person first needs both.
 * - **input schema** is the action's own JSON Schema, plus an optional `idempotency_key`
 *   on writes — the `Idempotency-Key` header of the REST door, which a tool call has no
 *   header for.
 * - **annotations** come from the danger: a read is read-only, a destructive or critical
 *   action is marked destructive (so a client asks before running it), and the HTTP verb
 *   says whether repeating a call is harmless. Nothing here is "open world": every tool acts
 *   on this one environment and nothing beyond it.
 *
 * It runs the action through {@see ActionRunner} with the authenticated principal, so
 * authorization, validation, the transaction, idempotency and the audit trail are the ones
 * the REST door and the console get. It is listed only to a principal that may run it
 * ({@see shouldRegister()}), and the runner checks that again on every call: hiding a tool
 * is a courtesy to the agent's context, never the lock.
 *
 * Outcomes are a tool result in the REST envelope's terms: `{data, meta?}` on success,
 * and `{error, message, field?}` — the codes the API documents — as a tool error the agent
 * can read and correct, never a protocol error it cannot.
 */
final class ActionTool extends Tool
{
    /** The argument that carries the REST door's `Idempotency-Key` header. */
    public const string IDEMPOTENCY_KEY = 'idempotency_key';

    public const string APPROVAL_ID = 'approval_id';

    public function __construct(private readonly ActionDefinition $action) {}

    public function action(): ActionDefinition
    {
        return $this->action;
    }

    public function name(): string
    {
        return $this->action->toolName();
    }

    public function title(): string
    {
        return $this->action->name;
    }

    public function description(): string
    {
        $description = $this->action->summary
            ."\n\nAction `{$this->action->name}` · scope `{$this->action->scope}` · danger: {$this->action->danger->value}.";

        if ($this->action->danger->destructive()) {
            $description .= ' It removes or revokes something; confirm with the person before calling it.';
        }

        if ($this->action->danger->writes()) {
            $description .= ' Pass `'.self::IDEMPOTENCY_KEY.'` (any unique string) to make a retry safe: the same key and arguments return the first answer for 24 hours instead of running again.';
        }

        return $description;
    }

    /**
     * May $principal run $action at all — the principal's own answer, the one the runner
     * asks. Used to decide what is listed; never instead of the runner's check.
     */
    public static function permits(?Principal $principal, ActionDefinition $action): bool
    {
        if ($principal === null) {
            return false;
        }

        try {
            $principal->authorize($action);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    public function shouldRegister(McpCaller $caller): bool
    {
        return self::permits($caller->principal(), $this->action);
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        $danger = $this->action->danger;

        // Per instance, so not as class attributes — but named by the package's own
        // annotation classes, so the hint keys are the ones it would have written.
        return [
            (new IsReadOnly)->key() => ! $danger->writes(),
            (new IsDestructive)->key() => $danger->destructive(),
            (new IsIdempotent)->key() => in_array($this->action->method, ['GET', 'PUT', 'DELETE'], true),
            (new IsOpenWorld)->key() => false,
        ];
    }

    /**
     * The action's input schema, with `idempotency_key` beside it on a write.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        $schema = $this->action->input()->jsonSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        // Any action can be held for a person's approval when the key's policy says so.
        $properties[self::APPROVAL_ID] = [
            'type' => 'string',
            'description' => 'Only after an `approval_pending` answer, once `approval_status` says approved: the approval id. Repeat the call with exactly the same arguments.',
        ];

        if (! $this->action->danger->writes()) {
            $schema['properties'] = $properties;

            return $schema;
        }

        $properties[self::IDEMPOTENCY_KEY] = [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 255,
            'description' => 'Optional. A unique string for this request; a retry with the same key and arguments returns the first answer instead of running again (24 hours).',
        ];
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * @return array{name: string, title: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'annotations' => $this->annotations(),
        ];
    }

    public function handle(Request $request, McpCaller $caller, ActionRunner $runner): ResponseFactory
    {
        $principal = $caller->principal();

        if ($principal === null) {
            return self::refusal('unauthorized', 'A valid credential is required.');
        }

        /** @var array<string, mixed> $input */
        $input = $request->all();
        $idempotencyKey = $input[self::IDEMPOTENCY_KEY] ?? null;
        $approvalId = $input[self::APPROVAL_ID] ?? null;
        unset($input[self::IDEMPOTENCY_KEY], $input[self::APPROVAL_ID]);

        if ($idempotencyKey !== null && (! is_string($idempotencyKey) || $idempotencyKey === '' || strlen($idempotencyKey) > 255)) {
            return self::refusal('validation_failed', 'The idempotency_key must be a string of 1 to 255 characters.', self::IDEMPOTENCY_KEY, [
                'errors' => [self::IDEMPOTENCY_KEY => ['The idempotency_key must be a string of 1 to 255 characters.']],
            ]);
        }

        try {
            $result = $runner->run($this->action, $principal, $input, $idempotencyKey, is_string($approvalId) && $approvalId !== '' ? $approvalId : null, ActionVia::Mcp);
        } catch (ApprovalRequired $held) {
            // Not an error: the call is waiting for a person. Said in a shape an agent can
            // act on without parsing prose.
            $body = [
                'status' => 'approval_pending',
                'approval' => [
                    'id' => $held->approvalId,
                    'binding_code' => $held->bindingCode,
                    'expires_at' => $held->expiresAt->toIso8601String(),
                ],
                'next' => 'Tell the person to approve the request showing code '.$held->bindingCode.' on their Cbox ID app. Poll `approval_status` with this approval id; once it is `approved`, call '.$this->name().' again with exactly the same arguments plus `approval_id`'.($this->action->danger->writes() ? ' (and the same `idempotency_key`, if you sent one)' : '').'.',
            ];

            return Response::make([Response::text('Waiting for approval · code '.$held->bindingCode), Response::text(self::json($body))])
                ->withStructuredContent($body);
        } catch (ActionRefused $refused) {
            return self::refusal($refused->error, $refused->getMessage(), $refused->field);
        } catch (ValidationException $invalid) {
            $first = array_key_first($invalid->errors());

            return self::refusal('validation_failed', $invalid->getMessage(), is_string($first) ? $first : null, ['errors' => $invalid->errors()]);
        } catch (AuthorizationException $forbidden) {
            return self::refusal('forbidden', $forbidden->getMessage() !== '' ? $forbidden->getMessage() : 'This credential may not perform that action.');
        }

        return $this->success($result);
    }

    /**
     * `{data, meta?}` as structured content — the REST body, so a client that knows one
     * knows the other — after one line a person skimming the transcript can read.
     */
    private function success(ActionResult $result): ResponseFactory
    {
        $body = ['data' => $result->payload];

        if ($result->meta !== null) {
            $body['meta'] = $result->meta;
        }

        if ($result->replayed) {
            // The REST door says this with `Idempotent-Replayed: true`.
            $body['replayed'] = true;
        }

        return Response::make([Response::text($this->summary($result)), Response::text(self::json($body))])
            ->withStructuredContent($body);
    }

    private function summary(ActionResult $result): string
    {
        $name = $this->action->name;

        if ($result->replayed) {
            return "{$name}: replayed the first answer to this idempotency_key; nothing ran again.";
        }

        if ($result->meta !== null && is_array($result->payload)) {
            $more = ($result->meta['has_more'] ?? false) === true ? ' More are available: pass `after` = meta.next_cursor.' : '';

            return "{$name}: ".count($result->payload).' item(s).'.$more;
        }

        return $result->payload === null ? "{$name}: done." : "{$name}: done. The result is below.";
    }

    /**
     * A refusal as a tool error: the REST error envelope, as JSON text and as structured
     * content, so the agent sees which field to fix and the stable code to switch on.
     *
     * @param  array<string, mixed>  $extra
     */
    private static function refusal(string $error, string $message, ?string $field = null, array $extra = []): ResponseFactory
    {
        $body = ['error' => $error, 'message' => $message];

        if ($field !== null) {
            $body['field'] = $field;
        }

        $body = [...$body, ...$extra];

        return Response::make(Response::error(self::json($body)))->withStructuredContent($body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '{"error":"server_error","message":"The result could not be encoded."}';
        }
    }
}
