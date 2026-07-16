<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\CustomDomain\Exceptions;

use RuntimeException;

/**
 * Thrown when a custom brand domain is malformed or points at a non-public host.
 * The message is safe to show a tenant (it never echoes the guard's internal
 * reachability detail).
 */
final class InvalidCustomDomain extends RuntimeException
{
    public static function malformed(string $host): self
    {
        return new self("“{$host}” is not a valid domain name.");
    }

    public static function unsafe(string $host): self
    {
        return new self("“{$host}” cannot be used as a custom domain.");
    }
}
