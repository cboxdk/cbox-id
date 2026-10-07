<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use RuntimeException;

/**
 * An action declining, with a stable code a machine switches on and a sentence a person
 * reads. `field` names the input it is about, which is where the console shows it.
 *
 * `fields` is for the refusal that is about several inputs at once — every field of a
 * policy that would loosen its floor, every blank parameter a provider needs. Reported one
 * at a time, somebody fixes the first and is refused for the second; so the console shows
 * each on its own field, and a machine reads all of them in the one message.
 */
final class ActionRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $fields  Input name => what is wrong with it.
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  non-empty-array<string, string>  $fields  Input name => what is wrong with it.
     */
    public static function onFields(string $error, array $fields): self
    {
        return new self($error, implode(' ', $fields), 422, (string) array_key_first($fields), $fields);
    }

    public static function notFound(string $resource): self
    {
        return new self('not_found', ucfirst($resource).' not found.', 404);
    }

    public static function because(string $error, string $message, ?string $field = null): self
    {
        return new self($error, $message, 422, $field);
    }
}
