<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SignedInSubject;
use Cbox\Id\Identity\GuardSignedInSubject;

/**
 * Who is signed in, for the package endpoints that need to know.
 *
 * This application does not use Laravel's guard. One person is one session, and which
 * account, environment and organization they are acting in are SELECTIONS on top of it —
 * three things a guard cannot model, which is why {@see PlatformAuth} keeps the session
 * itself. The package's default {@see GuardSignedInSubject} therefore
 * answered null here, forever.
 *
 * That was not a missing feature, it was a broken one. `/oauth/logout` cleared this
 * browser's session, answered 200 and redirected — so logout looked correct — while the
 * revocation behind it never ran. Sessions stayed Active, other devices stayed signed in,
 * and the person's own session list went on listing sessions they had just ended. A
 * relying party using RP-initiated logout as global sign-out got success and no global
 * sign-out.
 */
final class PlatformSignedInSubject implements SignedInSubject
{
    public function __construct(
        private readonly CurrentUser $me,
        private readonly Impersonation $impersonation,
    ) {}

    public function id(): ?string
    {
        // NOBODY, WHILE IMPERSONATING. During impersonation this browser IS the subject —
        // that is the stated invariant, and it is what makes the feature work — so
        // `CurrentUser::id()` answers with the CUSTOMER's user. The one caller of this
        // contract revokes every session that person holds, everywhere.
        //
        // An operator reproducing a bug inside a customer's account would then click that
        // customer app's "Sign out", and the real person's laptop and phone would be
        // signed out by somebody else, recorded as `ActorType::System` with no actor id.
        // An impersonated session may end ITSELF — the console's own exit does that — and
        // may not reach past this browser.
        if ($this->impersonation->isImpersonating()) {
            return null;
        }

        $id = $this->me->id();

        // `CurrentUser::id()` answers '' rather than null for "nobody", and an empty
        // string reaching `revokeAllForUser()` is a query with no predicate.
        return $id === '' ? null : $id;
    }
}
