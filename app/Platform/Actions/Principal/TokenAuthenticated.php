<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\OAuth\ManagementStepUp;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;

/**
 * A principal that is a PERSON'S ACCESS TOKEN, and can say how that person signed in.
 *
 * The token's RFC 9470 `acr` and `auth_time` — the class and the moment of the sign-in it
 * was issued from — are what {@see ManagementStepUp} asks before a Critical action, so a
 * deployment can demand a recent second factor of an agent's token the way an app's API
 * can of its own. A management key is not one of these: it is not a sign-in, and has
 * neither fact to offer.
 */
interface TokenAuthenticated
{
    /** The introspected token, or null when the principal was built without one. */
    public function token(): ?Introspection;
}
