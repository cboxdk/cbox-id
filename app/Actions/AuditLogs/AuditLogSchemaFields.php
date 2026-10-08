<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Http\Resources\Environment\Timestamp;
use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\AuditLogs\EventShape;
use App\Platform\AuditLogs\MetadataSchema;

/**
 * What an audit-log schema is made of, for the actions that create and replace one, and
 * how every door presents it.
 */
final class AuditLogSchemaFields
{
    /**
     * The schema's body — everything but the action it is for.
     *
     * @return list<Field>
     */
    public static function body(): array
    {
        return [
            Field::list('targets', Field::object('target', [
                Field::string('type')->required()->max(100)->describe('A target type events of this action may name.'),
                Field::object('metadata', [])->nullable()->describe('A metadata schema for targets of this type.'),
            ])->required())->nullable()->max(50)->describe('The target types this action\'s events may name, each with an optional metadata schema. Leave out to allow any.'),
            Field::object('actor_metadata', [])->nullable()->describe('A metadata schema (a subset of JSON Schema) for the actor\'s metadata.'),
            Field::object('metadata', [])->nullable()->describe('A metadata schema (a subset of JSON Schema) for the event\'s own metadata.'),
        ];
    }

    /**
     * The body, checked: every metadata schema is one {@see MetadataSchema} understands, and
     * no target type is listed twice. Refused with every problem on the field it is about.
     *
     * @return array{targets: list<array{type: string, metadata: array<mixed>|null}>|null, actor_metadata: array<mixed>|null, metadata: array<mixed>|null}
     *
     * @throws ActionRefused
     */
    public static function checked(ActionContext $context): array
    {
        $problems = [];
        $metadata = self::schema($context, 'metadata', $problems);
        $actorMetadata = self::schema($context, 'actor_metadata', $problems);
        $targets = null;

        if ($context->has('targets') && is_array($context->input['targets'])) {
            $targets = [];
            $seen = [];

            foreach (array_values($context->input['targets']) as $index => $target) {
                $type = is_array($target) && is_string($target['type'] ?? null) ? $target['type'] : '';
                $schema = is_array($target) && is_array($target['metadata'] ?? null) && $target['metadata'] !== [] ? $target['metadata'] : null;

                if (isset($seen[$type])) {
                    $problems["targets.{$index}.type"] = "`{$type}` is listed twice.";
                }

                $seen[$type] = true;

                if ($schema !== null && ($found = MetadataSchema::problems($schema)) !== []) {
                    $problems["targets.{$index}.metadata"] = implode(' ', $found);
                }

                $targets[] = ['type' => $type, 'metadata' => $schema];
            }
        }

        if ($problems !== []) {
            throw ActionRefused::onFields('invalid_schema', $problems);
        }

        return ['targets' => $targets, 'actor_metadata' => $actorMetadata, 'metadata' => $metadata];
    }

    /**
     * @throws ActionRefused
     */
    public static function action(string $action): string
    {
        if (! EventShape::validAction($action)) {
            throw ActionRefused::because('invalid_action', 'An action is dotted words of letters, digits, `_` and `-`, e.g. `invoice.voided`.', 'action');
        }

        return $action;
    }

    /**
     * `AuditLogSchema` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(AuditLogSchema $schema): array
    {
        return [
            'id' => $schema->id,
            'action' => $schema->action,
            'version' => $schema->version,
            'targets' => $schema->targets === null ? null : array_map(static fn (array $target): array => [
                'type' => $target['type'] ?? null,
                'metadata' => self::object($target['metadata'] ?? null),
            ], $schema->targets),
            'actor_metadata' => self::object($schema->actor_metadata),
            'metadata' => self::object($schema->metadata),
            'created_at' => Timestamp::of($schema->created_at),
            'updated_at' => Timestamp::of($schema->updated_at),
        ];
    }

    /**
     * A schema as JSON returns it: an object even when it is `{}`, never `[]`.
     */
    private static function object(mixed $value): ?object
    {
        return is_array($value) ? (object) $value : null;
    }

    /**
     * @param  array<string, string>  $problems
     * @return array<mixed>|null
     */
    private static function schema(ActionContext $context, string $field, array &$problems): ?array
    {
        $value = $context->input[$field] ?? null;

        if (! is_array($value) || $value === []) {
            return null;
        }

        $found = MetadataSchema::problems($value);

        if ($found !== []) {
            $problems[$field] = implode(' ', $found);
        }

        return $value;
    }
}
