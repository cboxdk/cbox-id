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

        // A person's access token runs the actions their own console offers — and the
        // document says so where it is true, rather than on every operation of the plane.
        if ($action->plane === ActionPlane::Environment && $action->consoleGate === ConsoleGate::Administer) {
            $operation['security'] = [['EnvironmentApiKey' => []], ['ManagementAccessToken' => []]];
        }

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
            '202' => ['$ref' => '#/components/responses/ApprovalRequired'],
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

        return $operation;
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
     * What a key needs to run it. On the workspace plane that is the scope AND a role that
     * holds the capability the scope and the action ask for ({@see WorkspaceScopes}) — the
     * part a reader would otherwise find out from a 403.
     */
    private function requirement(ActionDefinition $action): string
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

    private function tag(ActionDefinition $action): string
    {
        return $action->tag ?? ucfirst(str_replace('_', ' ', explode('.', $action->name)[0]));
    }
}
