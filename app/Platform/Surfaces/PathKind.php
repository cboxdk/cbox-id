<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

/**
 * Which surfaces a PATH belongs to ({@see Surfaces::kindOf()}).
 *
 * A path nobody listed is {@see Console}: a new route exists on the app host only, until
 * somebody decides it belongs to the issuer too.
 */
enum PathKind: string
{
    /** Probes and metrics: answered on every host, the unmapped ones included. */
    case Infrastructure = 'infrastructure';

    /** Answered on every surface that has a name (assets, sign-out, the language picker). */
    case Shared = 'shared';

    /** The issuer: sign-in, sign-up, consent, `/oauth/*`, discovery, JWKS, the frontend API. */
    case Issuer = 'issuer';

    /** A person's own security pages: password, second factor, passkeys, sessions, sudo. */
    case Account = 'account';

    /** Everything else: the console and the operator plane. */
    case Console = 'console';
}
