<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Exceptions;

use App\Platform\OAuth\Enums\OrganizationCreationRefusal;
use RuntimeException;

/**
 * The hosted create-an-organization step refused — with the reason and the sentence to show.
 *
 * The sentence is in the visitor's language: the only place it is ever shown is that
 * hosted step, and the locale is resolved before the request reaches the service that throws.
 */
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
            __('oauth.create_organization.not_offered'),
        );
    }

    public static function tooMany(int $seconds): self
    {
        return new self(
            OrganizationCreationRefusal::TooMany,
            trans_choice('oauth.create_organization.too_many', max(1, (int) ceil($seconds / 60))),
        );
    }
}
