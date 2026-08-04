<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\CustomDomain\Exceptions;

use RuntimeException;

/**
 * Thrown when a custom brand domain is malformed or points at a non-public host.
 * The message is safe to show a tenant (it never echoes the guard's internal
 * reachability detail).
 */
class InvalidCustomDomain extends RuntimeException
{
    public static function malformed(string $host): self
    {
        return new self("“{$host}” is not a valid domain name.");
    }

    public static function unsafe(string $host): self
    {
        return new self("“{$host}” cannot be used as a custom domain.");
    }

    public static function taken(string $host): self
    {
        return new self("“{$host}” is already in use by another environment.");
    }

    /**
     * A host under one of the deployment's own base domains. Named separately from
     * {@see taken()} because it is refused whether or not anyone holds it: those names
     * are how every tenant's `{slug}.{base}` plane is addressed, so they are not a
     * tenant's to claim.
     */
    public static function reserved(string $host): self
    {
        return new self("“{$host}” is reserved by this platform and cannot be used as a custom domain.");
    }
}
