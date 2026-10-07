<?php

declare(strict_types=1);

namespace App\Platform\Actions\OpenApi;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Input\Field;

/**
 * The management API's OpenAPI document, with every action's operation generated from the
 * action itself.
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

            if (isset($paths[$action->path][$method])) {
                continue;
            }

            $paths[$action->path][$method] = $this->operation($action);

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

        $operation = [
            'tags' => [$this->tag($action)],
            'summary' => $action->summary,
            'description' => "Requires scope `{$action->scope}`. Danger: {$action->danger->value}.",
            'operationId' => $action->toolName(),
            'x-action' => $action->name,
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
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

        $isList = $action->method === 'GET' && in_array('after', array_map(static fn (Field $field): string => $field->name, $action->input()->fields), true);

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

    private function tag(ActionDefinition $action): string
    {
        return $action->tag ?? ucfirst(str_replace('_', ' ', explode('.', $action->name)[0]));
    }
}
