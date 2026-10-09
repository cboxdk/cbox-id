<?php

declare(strict_types=1);

namespace App\Actions\Fga;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use Cbox\Id\Kernel\Authorization\Exceptions\AuthorizationModelException;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidConsistencyToken;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidSchema;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidTuple;
use Cbox\Id\Kernel\Authorization\Exceptions\SchemaConflict;
use Cbox\Id\Kernel\Authorization\Schema\AuthorizationSchema;
use Cbox\Id\Kernel\Authorization\Schema\SchemaError;
use Cbox\Id\Kernel\Authorization\ValueObjects\Check;
use Cbox\Id\Kernel\Authorization\ValueObjects\CheckResult;
use Cbox\Id\Kernel\Authorization\ValueObjects\ObjectList;
use Cbox\Id\Kernel\Authorization\ValueObjects\ResourceRef;
use Cbox\Id\Kernel\Authorization\ValueObjects\SchemaState;
use Cbox\Id\Kernel\Authorization\ValueObjects\SubjectRef;
use Cbox\Id\Kernel\Authorization\ValueObjects\Tuple;
use Closure;

/**
 * What every fine-grained authorization action shares: the shape of a tuple, a subject and
 * a check on the way in, how each is presented on the way out, and how the framework's
 * refusals ({@see AuthorizationModelException}) become the action's.
 *
 * One shape for every door: a tuple is the same object in a write, a delete, a list and
 * the console's form, and a refusal carries the framework's own stable code
 * (`invalid_schema`, `invalid_tuple`, `schema_conflict`, `unknown_relation`,
 * `resolution_too_complex`, `invalid_consistency_token`, `schema_not_defined`).
 */
final class FgaFields
{
    /** The tag every one of these actions is listed under in the API reference. */
    public const string TAG = 'Fine-grained authorization';

    /** The most tuples one write or delete takes, and the most checks one batch asks. */
    public const int MAX_BATCH = 100;

    /** The most ids a list query returns per page. */
    public const int MAX_LIST = 1000;

    /**
     * @return list<Field>
     */
    public static function tupleFields(): array
    {
        return [
            Field::string('resource_type')->required()->max(64)->describe('The resource\'s type, as the schema names it: `document`.'),
            Field::string('resource_id')->required()->max(128)->describe('Your id for the resource: `readme`.'),
            Field::string('relation')->required()->max(64)->describe('The relation: `viewer`.'),
            self::subject()->required(),
        ];
    }

    /**
     * A check's fields, flat: a read travels in the query string.
     *
     * @return list<Field>
     */
    public static function checkFields(): array
    {
        return [
            Field::string('resource_type')->required()->max(64)->describe('The resource\'s type: `document`.'),
            Field::string('resource_id')->required()->max(128)->describe('The resource\'s id: `readme`.'),
            Field::string('relation')->required()->max(64)->describe('The relation to check: `viewer`.'),
            Field::string('subject_type')->required()->max(64)->describe('The subject\'s type: `user`, or `group` for a userset.'),
            Field::string('subject_id')->required()->max(128)->describe('The subject\'s id: `alice`.'),
            Field::string('subject_relation')->max(64)->describe('For a userset subject — `member` with `group`/`eng` asks about every member of eng.'),
        ];
    }

    public static function flatCheckFrom(ActionContext $context): Check
    {
        return Check::of(
            $context->string('resource_type'),
            $context->string('resource_id'),
            $context->string('relation'),
            SubjectRef::of($context->string('subject_type'), $context->string('subject_id'), $context->nullableString('subject_relation')),
        );
    }

    public static function subject(): Field
    {
        return Field::object('subject', [
            Field::string('type')->required()->max(64)->describe('The subject\'s type: `user`, or `group` for a userset.'),
            Field::string('id')->required()->max(128)->describe('Your id for the subject: `alice`, `eng`.'),
            Field::string('relation')->nullable()->max(64)->describe('For a userset — everybody with this relation on the subject: `member` of `group:eng`. Omit for one subject.'),
        ])->describe('Who: one subject (`user:alice`), or a userset (`group:eng#member`).');
    }

    public static function tuples(string $verb): Field
    {
        return Field::list('tuples', Field::object('tuple', self::tupleFields())->required())
            ->required()->min(1)->max(self::MAX_BATCH)
            ->describe("1–100 tuples to {$verb}, all together or none.");
    }

    public static function consistency(): Field
    {
        return Field::string('consistency_token')->nullable()->max(64)
            ->describe('A `consistency_token` a write returned: the answer is then at least as fresh as that write.');
    }

    /**
     * @param  array<mixed>  $input
     */
    public static function tupleFrom(array $input): Tuple
    {
        return new Tuple(
            ResourceRef::of(self::text($input['resource_type'] ?? null), self::text($input['resource_id'] ?? null)),
            self::text($input['relation'] ?? null),
            self::subjectFrom(is_array($input['subject'] ?? null) ? $input['subject'] : []),
        );
    }

    /**
     * @param  array<mixed>  $input
     */
    public static function subjectFrom(array $input): SubjectRef
    {
        $relation = $input['relation'] ?? null;

        return SubjectRef::of(self::text($input['type'] ?? null), self::text($input['id'] ?? null), is_string($relation) ? $relation : null);
    }

    /**
     * @return list<Tuple>
     */
    public static function tuplesFrom(ActionContext $context): array
    {
        return array_values(array_map(self::tupleFrom(...), array_filter($context->array('tuples'), is_array(...))));
    }

    /**
     * `FgaTuple` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function presentTuple(Tuple $tuple): array
    {
        return [...$tuple->toArray(), 'tuple' => (string) $tuple];
    }

    /**
     * `FgaCheck` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function presentCheck(CheckResult $result): array
    {
        return [
            'allowed' => $result->allowed,
            'resource_type' => $result->check->resource->type,
            'resource_id' => $result->check->resource->id,
            'relation' => $result->check->relation,
            'subject' => $result->check->subject->toArray(),
            'consistency_token' => (string) $result->consistency,
        ];
    }

    /**
     * `FgaSchema` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function presentSchema(SchemaState $state): array
    {
        return [
            'defined' => $state->defined(),
            'schema' => $state->source,
            'version' => $state->version,
            'types' => $state->schema?->toArray()['types'] ?? [],
            'updated_at' => $state->updatedAt?->format(DATE_ATOM),
            'consistency_token' => (string) $state->consistency,
        ];
    }

    /**
     * `FgaSchemaValidation` in the spec.
     *
     * @param  list<SchemaError>  $errors
     * @return array<string, mixed>
     */
    public static function presentValidation(?AuthorizationSchema $schema, array $errors): array
    {
        return [
            'valid' => $errors === [],
            'errors' => array_map(static fn (SchemaError $error): array => $error->toArray(), $errors),
            'types' => $schema?->toArray()['types'] ?? [],
            'canonical' => $schema?->toDsl(),
        ];
    }

    /**
     * `FgaObject` in the spec, one per id.
     *
     * @return list<array{type: string, id: string}>
     */
    public static function presentObjects(ObjectList $list): array
    {
        return array_map(static fn (string $id): array => ['type' => $list->type, 'id' => $id], $list->ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function objectMeta(ObjectList $list, int $limit): array
    {
        return [
            'limit' => $limit,
            'has_more' => $list->hasMore(),
            'next_cursor' => $list->nextCursor,
            'consistency_token' => (string) $list->consistency,
        ];
    }

    public static function limit(ActionContext $context, int $default, int $max): int
    {
        $asked = $context->input['limit'] ?? $default;

        return min($max, max(1, is_numeric($asked) ? (int) $asked : $default));
    }

    /**
     * Run $call, turning the framework's refusals into this action's: the same code, the
     * same sentence, on the input field it is about.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws ActionRefused
     */
    public static function refusing(Closure $call): mixed
    {
        try {
            return $call();
        } catch (AuthorizationModelException $refused) {
            throw new ActionRefused(
                $refused->errorCode(),
                $refused->getMessage(),
                // A conflict with the tuples already stored is the one refusal about STATE a
                // retry cannot fix by changing the input; everything else is the input's.
                $refused instanceof SchemaConflict ? 409 : 422,
                match (true) {
                    $refused instanceof InvalidSchema => 'schema',
                    $refused instanceof InvalidConsistencyToken => 'consistency_token',
                    $refused instanceof InvalidTuple => 'tuples',
                    default => null,
                },
            );
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
