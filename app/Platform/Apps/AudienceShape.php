<?php

declare(strict_types=1);

namespace App\Platform\Apps;

/**
 * Which of the token endpoint's three answers an app's scopes lead to when it asks for a
 * token without naming an API (`resource`).
 */
enum AudienceShape: string
{
    /** No registered API is involved: `aud` is the issuer, as it always was. */
    case Issuer = 'issuer';

    /** Every registered scope belongs to one API: `aud` is that API's identifier. */
    case Api = 'api';

    /**
     * Scopes of more than one API: a request for all of them names no single audience and
     * is refused (`invalid_target`), so the app has to name one with `resource`.
     */
    case Several = 'several';
}
