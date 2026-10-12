<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A write refused because the person acting has not confirmed their email address.
 *
 * Still an authorization failure — the API answers 403 with the sentence, as before — but a
 * type of its own so a console form can be sent back to the page it came from with that
 * sentence, instead of the generic "Access denied" page that swallowed it. An operator who
 * set up a provider on cboxid.com saw exactly that, with no hint that one click in their
 * inbox was the whole fix.
 */
final class EmailNotVerified extends AuthorizationException {}
