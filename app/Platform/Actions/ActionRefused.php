<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use RuntimeException;

/**
 * An action declining, with a stable code a machine switches on and a sentence a person
 * reads. `field` names the input it is about, which is where the console shows it.
 */
final class ActionRefused extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
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
