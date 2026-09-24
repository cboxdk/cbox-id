<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\Exceptions;

use RuntimeException;

/**
 * This browser holds an open support session for the app, and the app's authorization
 * request asks for something the session cannot give.
 *
 * A support session is ONE person in ONE organization, chosen by the administrator who
 * started it. Answering a request for another organization with a code for the session's
 * would hand the app a token for a team it did not ask for (id-js refuses exactly that);
 * falling through to an ordinary sign-in would show a stranger's sign-in page to an
 * administrator who is nobody on this tenant. So the app is told, with `access_denied`
 * and this sentence, and the session stays open for a request it can answer.
 */
final class SupportRequestRefused extends RuntimeException
{
    public static function otherOrganization(): self
    {
        return new self('This browser holds a support session for another organization of this person. Sign in to the app without naming an organization, or end the support session first.');
    }

    public static function cannotCreateOrganization(): self
    {
        return new self('A support session signs in as somebody; it cannot create an organization for them.');
    }
}
