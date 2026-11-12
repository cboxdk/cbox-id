<?php

declare(strict_types=1);

namespace App\Platform\Environments;

use RuntimeException;

/** {@see EnvironmentErasure} refused: nothing was removed. */
final class EnvironmentNotErasable extends RuntimeException
{
    /**
     * @param  string|null  $protection  `default`, `reserved` or `configured`
     * @param  list<string>  $tables  tables with rows of the environment and no decision
     */
    private function __construct(string $message, public readonly ?string $protection = null, public readonly array $tables = [])
    {
        parent::__construct($message);
    }

    public static function protected(string $protection): self
    {
        return new self("The environment is protected ({$protection}) and is never deleted.", protection: $protection);
    }

    /** @param list<string> $tables */
    public static function undecided(array $tables): self
    {
        return new self('Tables hold rows of the environment and have no decision in ErasedTables: '.implode(', ', $tables).'.', tables: $tables);
    }
}
