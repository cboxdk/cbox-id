<?php

declare(strict_types=1);

namespace App\Platform\Actions\OpenApi;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\WorkspaceScopes;

/**
 * A management API's OpenAPI document — one per plane — with every action's operation
 * generated from the action itself.
 *
 * `resources/openapi/base/<plane>.yaml` is the hand-written part: the description, the
 * components, the endpoints that are not actions, and — where an action deserves more than
 * its summary — a hand-written operation, which wins. Every action the base does not
 * describe is generated from its {@see ActionDefinition}: method, path, scope, input schema,
 * success status and response shape, and the standard refusals. So a new action is
 * documented, validated by the contract tests and visible to generated clients the moment
 * it exists, and nobody edits a 2,000-line file to add one.
 */
final readonly class ActionOpenApi
{
    /** The body of the `ApprovalRequired` response, as each base document declares it. */
    public const array APPROVAL_REQUIRED = [
        'type' => 'object',
        'required' => ['error', 'message', 'approval'],
        'properties' => [
            'error' => ['type' => 'string', 'enum' => ['approval_required']],
            'message' => ['type' => 'string'],
            'approval' => [
                'type' => 'object',
                'required' => ['id', 'status', 'binding_code', 'expires_at', 'poll_url'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'binding_code' => ['type' => 'string'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'poll_url' => ['type' => 'string', 'format' => 'uri'],
                ],
            ],
        ],
    ];

    public function __construct(private ActionRegistry $registry) {}

    /**
     * The complete document: the base, plus every action it does not already describe.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function build(array $base, ActionPlane $plane): array
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = is_array($base['paths'] ?? null) ? $base['paths'] : [];
        /** @var list<array<string, mixed>> $tags */
        $tags = is_array($base['tags'] ?? null) ? array_values($base['tags']) : [];
        $tagNames = array_map(static fn (array $tag): mixed => $tag['name'] ?? null, $tags);

        foreach ($this->registry->forPlane($plane) as $action) {
            $method = strtolower($action->method);
            $path = $action->documentedPath();

            if (isset($paths[$path][$method])) {
                // Hand-written, and it wins — but any action can be held for a person's
                // approval, and every environment action is reached from the platform root
                // as well, whoever wrote its operation.
                if (is_array($paths[$path][$method])) {
                    $paths[$path][$method] = self::described(self::holdable($paths[$path][$method]), $action);
                }

                if (is_array($paths[$path][$method]) && $plane === ActionPlane::Environment) {
                    $paths[$path][$method] = $this->fromTheRoot($paths[$path][$method], $action);
                }

                continue;
            }

            $paths[$path][$method] = $this->operation($action);

            $tag = $this->tag($action);

            if (! in_array($tag, $tagNames, true)) {
                $tags[] = ['name' => $tag];
                $tagNames[] = $tag;
            }
        }

        ksort($paths);
        $base['tags'] = $tags;
        $base['paths'] = $paths;

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(ActionDefinition $action): array
    {
        $fields = $action->input()->fields;
        $inPath = array_values(array_filter($fields, static fn (Field $field): bool => $field->isInPath()));
        $rest = array_values(array_filter($fields, static fn (Field $field): bool => ! $field->isInPath()));
        $reads = ! $action->danger->writes();

        $parameters = array_map(static fn (Field $field): array => [
            'name' => $field->name,
            'in' => 'path',
            'required' => true,
            'schema' => $field->schema(),
        ], $inPath);

        if ($reads) {
            foreach ($rest as $field) {
                $parameters[] = ['name' => $field->name, 'in' => 'query', 'required' => $field->isRequired(), 'schema' => $field->schema()];
            }
        } else {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }

        $parameters[] = ['$ref' => '#/components/parameters/CboxApproval'];

        $operation = [
            'tags' => [$this->tag($action)],
            'summary' => $action->summary,
            'description' => $this->requirement($action)." Danger: {$action->danger->value}.",
            'operationId' => $action->toolName(),
            'x-action' => $action->name,
            // Machine-readable twins of the sentence above, for generated clients.
            'x-scope' => $action->scope,
            'x-danger' => $action->danger->value,
        ];

        $operation['parameters'] = $parameters;

        if (! $reads && $rest !== []) {
            $required = array_values(array_map(static fn (Field $field): string => $field->name, array_filter($rest, static fn (Field $field): bool => $field->isRequired())));
            $schema = ['type' => 'object', 'properties' => []];

            foreach ($rest as $field) {
                $schema['properties'][$field->name] = $field->schema();
            }

            if ($required !== []) {
                $schema['required'] = $required;
            }

            $operation['requestBody'] = [
                'required' => $required !== [],
                'content' => ['application/json' => ['schema' => $schema]],
            ];
        }

        $operation['responses'] = [
            (string) $action->status => $this->success($action),
            // Any action can be held for a person's approval when the key's policy says so.
            // An action whose own answer is 202 shares the status: both bodies, told apart by
            // `error: approval_required` — overwriting it left generated clients without the
            // action's real answer.
            '202' => $action->status === 202
                ? $this->acceptedOrHeld($action)
                : ['$ref' => '#/components/responses/ApprovalRequired'],
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
        ];

        if ($inPath !== []) {
            $operation['responses']['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if (! $reads) {
            $operation['responses']['409'] = ['$ref' => '#/components/responses/Conflict'];
        }

        $operation['responses']['422'] = ['$ref' => '#/components/responses/UnprocessableEntity'];
        $operation['responses']['429'] = ['$ref' => '#/components/responses/TooManyRequests'];

        return $action->plane === ActionPlane::Environment ? $this->fromTheRoot($operation, $action) : $operation;
    }

    /**
     * What an environment action's operation says about the credentials beside a key.
     *
     * A person's access token from the ENVIRONMENT's issuer runs the actions their own
     * organization console offers — and the document says so where it is true, rather than
     * on every operation of the plane. A workspace member's token from the PLATFORM ROOT
     * runs every action the environment console runs, on the root's host, in the
     * environment the `Cbox-Environment` header names: so every operation takes the header,
     * and may answer `400 environment_required` without it and `404` for an environment
     * the person cannot reach.
     *
     * @param  array<mixed>  $operation  an operation object — its keys are field names
     * @return array<string, mixed>
     */
    private function fromTheRoot(array $operation, ActionDefinition $action): array
    {
        $security = [['EnvironmentApiKey' => []]];

        if ($action->consoleGate === ConsoleGate::Administer) {
            $security[] = ['ManagementAccessToken' => []];
        }

        if ($action->consoleGate === ConsoleGate::Administer || $action->consoleGate === ConsoleGate::EnvironmentAdmin) {
            $security[] = ['WorkspaceAccessToken' => []];
        }

        $parameters = is_array($operation['parameters'] ?? null) ? array_values($operation['parameters']) : [];
        $environment = ['$ref' => '#/components/parameters/CboxEnvironment'];

        if (! in_array($environment, $parameters, true)) {
            $parameters[] = $environment;
        }

        // The security right after the parameters, where an operation states it — so the
        // document reads in the order it always has.
        $ordered = [];

        foreach ($operation as $key => $value) {
            if (! is_string($key) || $key === 'security') {
                continue;
            }

            $ordered[$key] = $key === 'parameters' ? $parameters : $value;

            if ($key === 'parameters') {
                $ordered['security'] = $security;
            }
        }

        $ordered['parameters'] ??= $parameters;
        $ordered['security'] ??= $security;
        $operation = $ordered;

        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $responses = self::withResponse($responses, 400, ['$ref' => '#/components/responses/EnvironmentRequired']);
        $operation['responses'] = self::withResponse($responses, 404, ['$ref' => '#/components/responses/NotFound']);

        return $operation;
    }

    /**
     * The machine-readable action facts a generated operation carries — `x-action`,
     * `x-scope`, `x-danger` — on a hand-written one too, so a generated client reads every
     * operation the same way instead of parsing prose for the ones a person wrote. A value
     * the base already states is left as written.
     *
     * @param  array<mixed>  $operation
     * @return array<mixed>
     */
    private static function described(array $operation, ActionDefinition $action): array
    {
        $operation['x-action'] ??= $action->name;
        $operation['x-scope'] ??= $action->scope;
        $operation['x-danger'] ??= $action->danger->value;

        return $operation;
    }

    /**
     * What a hand-written operation says about approvals, as a generated one does: it takes
     * `Cbox-Approval`, and it may answer `202 approval_required`.
     *
     * ANY action can be held — a key's step-up policy names actions by danger or by name, and
     * a person's token holds every critical one — so an operation that leaves the 202 out
     * documents an answer it gives as one it never gives. The 46 hand-written operations
     * did, and the first held `POST /apps` failed the contract test.
     *
     * @param  array<mixed>  $operation  an operation object — its keys are field names
     * @return array<mixed>
     */
    private static function holdable(array $operation): array
    {
        $parameters = is_array($operation['parameters'] ?? null) ? array_values($operation['parameters']) : [];
        $approval = ['$ref' => '#/components/parameters/CboxApproval'];

        if (! in_array($approval, $parameters, true)) {
            $parameters[] = $approval;

            // Where an operation states its parameters: before its body and its answers.
            $ordered = [];

            foreach ($operation as $key => $value) {
                if (! isset($ordered['parameters']) && ($key === 'parameters' || $key === 'requestBody' || $key === 'responses')) {
                    $ordered['parameters'] = $parameters;
                }

                if ($key !== 'parameters') {
                    $ordered[$key] = $value;
                }
            }

            $ordered['parameters'] ??= $parameters;
            $operation = $ordered;
        }

        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $operation['responses'] = self::withResponse($responses, 202, ['$ref' => '#/components/responses/ApprovalRequired']);

        return $operation;
    }

    /**
     * $responses with $status added before the first status above it, unless it is there
     * already — so a hand-written operation keeps its own order and its own wording.
     *
     * @param  array<array-key, mixed>  $responses
     * @return array<array-key, mixed>
     */
    private static function withResponse(array $responses, int $status, mixed $response): array
    {
        if (array_key_exists($status, $responses)) {
            return $responses;
        }

        $merged = [];
        $placed = false;

        foreach ($responses as $key => $value) {
            if (! $placed && is_numeric($key) && (int) $key > $status) {
                $merged[$status] = $response;
                $placed = true;
            }

            $merged[$key] = $value;
        }

        if (! $placed) {
            $merged[$status] = $response;
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function success(ActionDefinition $action): array
    {
        if ($action->status === 204) {
            return ['description' => 'Done.'];
        }

        $item = $action->schema === null
            ? ['type' => 'object']
            : ['$ref' => '#/components/schemas/'.$action->schema];

        // A paged list — by cursor (`after`) on the environment plane, by number (`page`) on
        // the workspace plane — answers `data` as an array beside its `meta`.
        $names = array_map(static fn (Field $field): string => $field->name, $action->input()->fields);
        $isList = $action->method === 'GET' && array_intersect(['after', 'page'], $names) !== [];

        $schema = $isList
            ? [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => $item],
                    'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                ],
            ]
            : ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $item]];

        return [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * The 202 of an action that itself answers 202: its own body, or the approval hold.
     *
     * The hold's body is the `ApprovalRequired` response's schema in every plane's base
     * document; {@see self::APPROVAL_REQUIRED} repeats it because a schema inside `oneOf`
     * cannot point at a response, and a test holds the two equal.
     *
     * @return array<string, mixed>
     */
    private function acceptedOrHeld(ActionDefinition $action): array
    {
        $own = $this->success($action);
        $content = is_array($own['content'] ?? null) ? $own['content'] : [];
        $json = is_array($content['application/json'] ?? null) ? $content['application/json'] : [];
        $schema = $json['schema'] ?? ['type' => 'object'];

        return [
            'description' => 'Accepted — or held for a person\'s approval (`error: approval_required`): poll `approval.poll_url`, then repeat the request with `Cbox-Approval`.',
            'content' => ['application/json' => ['schema' => ['oneOf' => [$schema, self::APPROVAL_REQUIRED]]]],
        ];
    }

    /**
     * What a key needs to run it. On the workspace plane that is the scope AND a role that
     * holds the capability the scope and the action ask for ({@see WorkspaceScopes}) — the
     * part a reader would otherwise find out from a 403.
     *
     * Public because the generated actions reference (`php artisan docs:actions`) prints
     * the same sentence: one wording of a requirement, wherever it is read.
     */
    public function requirement(ActionDefinition $action): string
    {
        $sentence = "Requires scope `{$action->scope}`";

        if ($action->plane === ActionPlane::Platform) {
            return $sentence.' on an access token delegated by an active platform operator. No management key is accepted.';
        }

        if ($action->plane === ActionPlane::Account) {
            return $sentence.' on an access token you delegated; it acts on your own account only. No management key is accepted.';
        }

        if ($action->plane !== ActionPlane::Workspace) {
            return $sentence.'.';
        }

        $capabilities = array_values(array_unique(array_filter(
            [WorkspaceScopes::capability($action->scope), $action->consoleGate->capability()],
            'is_string',
        )));

        return $capabilities === []
            ? $sentence.' (any role).'
            : $sentence.' and a role that may '.implode(' and ', array_map(static fn (string $capability): string => "`{$capability}`", $capabilities)).'.';
    }

    /** The tag an action is listed under — in its document, and in the actions reference. */
    public function tag(ActionDefinition $action): string
    {
        return $action->tag ?? ucfirst(str_replace('_', ' ', explode('.', $action->name)[0]));
    }
}
