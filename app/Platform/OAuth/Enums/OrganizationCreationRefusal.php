<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Enums;

/**
 * Why the hosted "create an organization" step would not create one.
 *
 * A REASON rather than only a message, so a test can assert which rule refused: two
 * refusals throwing the same class is how a guard gets deleted with its test still green.
 */
enum OrganizationCreationRefusal: string
{
    /** This environment does not let people sign themselves up, so it does not let them found an organization either. */
    case NotOffered = 'not_offered';

    /** Too many organizations created by this person in the last hour. */
    case TooMany = 'too_many';
}
