<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Exceptions;

use App\Platform\OAuth\Enums\OrganizationCreationRefusal;
use RuntimeException;

/** The hosted create-an-organization step refused — with the reason and the sentence to show. */
final class OrganizationCreationRefused extends RuntimeException
{
    private function __construct(public readonly OrganizationCreationRefusal $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notOffered(): self
    {
        return new self(
            OrganizationCreationRefusal::NotOffered,
            'Creating an organization is not available here. Ask an administrator to invite you to one.',
        );
    }

    public static function tooMany(int $seconds): self
    {
        return new self(
            OrganizationCreationRefusal::TooMany,
            'You have created several organizations in a short time. Try again in '.max(1, (int) ceil($seconds / 60)).' minutes.',
        );
    }
}
