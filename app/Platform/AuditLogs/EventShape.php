<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Actions\AuditLogs\CreateAuditLogEvents;
use App\Models\AuditLogs\AuditLogSchema;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The rules every audit event obeys, whatever its action — and its schema's, when its
 * action has one.
 *
 * The action's input schema ({@see CreateAuditLogEvents}) has already checked the
 * STRUCTURE: the fields are there and are strings, objects and lists. This is what a
 * validator rule cannot say: an action name is dotted words, a time is a time and not one
 * in the future, metadata is a flat map of short names to small values. Bounded on purpose
 * — an audit event is a line in a log a person reads, not a place to store a document, and
 * every byte of it is kept for the retention period and hashed into a chain.
 */
final class EventShape
{
    public const int MAX_METADATA_KEYS = 50;

    public const int MAX_KEY_LENGTH = 40;

    public const int MAX_VALUE_LENGTH = 500;

    public const int MAX_TARGETS = 50;

    /** How far ahead of our clock an event may say it happened: clocks disagree a little. */
    public const int FUTURE_SKEW_SECONDS = 300;

    /** Dotted words: `invoice.voided`, `user.signed_in`, `report-export.downloaded`. */
    private const string ACTION = '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/';

    /** A metadata name: starts with a letter or underscore, so no name is ever read as a list index. */
    private const string KEY = '/^[A-Za-z_][A-Za-z0-9_.:\-]*$/';

    public static function validAction(string $action): bool
    {
        return preg_match(self::ACTION, $action) === 1;
    }

    public static function validMetadataKey(string $key): bool
    {
        return strlen($key) <= self::MAX_KEY_LENGTH && preg_match(self::KEY, $key) === 1;
    }

    /**
     * One event, checked and normalized, or the errors keyed by `{$path}.field`.
     *
     * @param  array<mixed>  $event  Already through the action's input rules.
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function check(array $event, string $path, ?AuditLogSchema $schema, bool $strict): array
    {
        $errors = [];
        $action = is_string($event['action'] ?? null) ? $event['action'] : '';

        if (! self::validAction($action)) {
            $errors["{$path}.action"] = 'An action is dotted words of letters, digits, `_` and `-`, e.g. `invoice.voided`.';
        } elseif ($schema === null && $strict) {
            $errors["{$path}.action"] = "`{$action}` has no schema, and this environment only accepts actions that have one.";
        }

        $occurredAt = self::time($event['occurred_at'] ?? null);

        if ($occurredAt === null) {
            $errors["{$path}.occurred_at"] = 'occurred_at must be an ISO 8601 date-time with a time zone, e.g. 2026-10-08T12:00:00.000Z.';
        } elseif ($occurredAt->isAfter(CarbonImmutable::now()->addSeconds(self::FUTURE_SKEW_SECONDS))) {
            $errors["{$path}.occurred_at"] = 'occurred_at is in the future.';
        }

        /** @var array<string, mixed> $actor */
        $actor = is_array($event['actor'] ?? null) ? $event['actor'] : [];
        $actorMetadata = self::metadata($actor['metadata'] ?? null, "{$path}.actor.metadata", $errors);

        $targets = [];
        $given = is_array($event['targets'] ?? null) ? array_values($event['targets']) : [];

        if (count($given) > self::MAX_TARGETS) {
            $errors["{$path}.targets"] = 'An event names at most '.self::MAX_TARGETS.' targets.';
            $given = [];
        }

        foreach ($given as $index => $target) {
            $target = is_array($target) ? $target : [];
            $targets[] = [
                'id' => self::string($target['id'] ?? null),
                'type' => self::string($target['type'] ?? null),
                'name' => self::nullableString($target['name'] ?? null),
                'metadata' => self::metadata($target['metadata'] ?? null, "{$path}.targets.{$index}.metadata", $errors),
            ];
        }

        /** @var array<string, mixed> $context */
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $metadata = self::metadata($event['metadata'] ?? null, "{$path}.metadata", $errors);

        if ($schema !== null) {
            $errors = [...$errors, ...self::againstSchema($schema, $path, $metadata, $actorMetadata, $targets)];
        }

        if ($errors !== [] || $occurredAt === null) {
            return [null, $errors];
        }

        return [[
            'organization_id' => self::string($event['organization_id'] ?? null),
            'action' => $action,
            'occurred_at' => $occurredAt,
            'schema_version' => $schema?->version,
            'actor' => [
                'id' => self::string($actor['id'] ?? null),
                'type' => self::string($actor['type'] ?? null),
                'name' => self::nullableString($actor['name'] ?? null),
                'metadata' => $actorMetadata,
            ],
            'targets' => $targets,
            'context' => [
                'location' => self::nullableString($context['location'] ?? null),
                'user_agent' => self::nullableString($context['user_agent'] ?? null),
            ],
            'metadata' => $metadata,
        ], []];
    }

    /**
     * The action's schema, applied: the target types it allows and what each kind of
     * metadata may hold.
     *
     * @param  array<string, string|int|float|bool|null>|null  $metadata
     * @param  array<string, string|int|float|bool|null>|null  $actorMetadata
     * @param  list<array{id: string, type: string, name: string|null, metadata: array<string, string|int|float|bool|null>|null}>  $targets
     * @return array<string, string>
     */
    private static function againstSchema(AuditLogSchema $schema, string $path, ?array $metadata, ?array $actorMetadata, array $targets): array
    {
        $errors = [];

        if (is_array($schema->metadata)) {
            $errors = [...$errors, ...MetadataSchema::violations($schema->metadata, $metadata, "{$path}.metadata")];
        }

        if (is_array($schema->actor_metadata)) {
            $errors = [...$errors, ...MetadataSchema::violations($schema->actor_metadata, $actorMetadata, "{$path}.actor.metadata")];
        }

        if ($schema->targets === null) {
            return $errors;
        }

        /** @var array<string, array<mixed>|null> $allowed target type => its metadata schema */
        $allowed = [];

        foreach ($schema->targets as $declared) {
            $type = $declared['type'] ?? null;

            if (is_string($type)) {
                $allowed[$type] = is_array($declared['metadata'] ?? null) ? $declared['metadata'] : null;
            }
        }

        foreach ($targets as $index => $target) {
            if (! array_key_exists($target['type'], $allowed)) {
                $errors["{$path}.targets.{$index}.type"] = "`{$target['type']}` is not a target type this action's schema allows (".implode(', ', array_keys($allowed)).').';

                continue;
            }

            $targetSchema = $allowed[$target['type']];

            if ($targetSchema !== null) {
                $errors = [...$errors, ...MetadataSchema::violations($targetSchema, $target['metadata'], "{$path}.targets.{$index}.metadata")];
            }
        }

        return $errors;
    }

    /**
     * Metadata, checked: a flat map of valid names to small scalar values. Null when absent
     * or empty, so "no metadata" has one spelling in the chain.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string|int|float|bool|null>|null
     */
    private static function metadata(mixed $value, string $path, array &$errors): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            $errors[$path] = 'Metadata is an object of names to values.';

            return null;
        }

        if (count($value) > self::MAX_METADATA_KEYS) {
            $errors[$path] = 'Metadata holds at most '.self::MAX_METADATA_KEYS.' keys.';

            return null;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (! self::validMetadataKey($key)) {
                $errors["{$path}.{$key}"] = 'A metadata name starts with a letter or `_`, holds letters, digits and `_ . : -`, and is at most '.self::MAX_KEY_LENGTH.' characters.';

                continue;
            }

            if (! is_scalar($item) && $item !== null) {
                $errors["{$path}.{$key}"] = 'A metadata value is a string, number, boolean or null — not a list or object.';

                continue;
            }

            if (is_string($item) && mb_strlen($item) > self::MAX_VALUE_LENGTH) {
                $errors["{$path}.{$key}"] = 'A metadata value is at most '.self::MAX_VALUE_LENGTH.' characters.';

                continue;
            }

            $clean[$key] = $item;
        }

        return $clean;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        // A time zone is required: a wall-clock time from somebody else's server is not an
        // instant, and guessing ours would move every event by however far apart we are.
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/i', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
