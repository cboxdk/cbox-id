<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use RuntimeException;

/**
 * The console's sign-in at the login host did not complete. `reason` is a short code for
 * the log and the page's support line, never a sentence shown as the explanation.
 */
final class ConsoleSignInFailed extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('Console sign-in failed: '.$reason);
    }

    public static function because(string $reason): self
    {
        return new self(preg_replace('/[^a-z0-9_]/', '', mb_strtolower($reason)) ?: 'failed');
    }
}
