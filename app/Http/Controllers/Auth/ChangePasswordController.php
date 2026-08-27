<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Middleware\Authenticate;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * THE FORCED PASSWORD CHANGE.
 *
 * An administrator handing out a temporary password is promising that the recipient must
 * replace it — otherwise "temporary" describes nothing, and a credential the administrator
 * knows becomes a permanent second way in. {@see Authenticate} holds
 * every authenticated request on this page until the requirement is cleared, so the change
 * cannot be walked around by typing a different URL.
 *
 * THE CURRENT PASSWORD IS DELIBERATELY NOT ASKED FOR. The person just proved it to reach
 * an authenticated session, and asking again would only tempt somebody who was handed a
 * password by their administrator into writing it down.
 *
 * That reasoning holds ONLY while this page is genuinely reachable just by somebody who
 * owes a change, which is what the guard in {@see self::update()} enforces. The middleware
 * EXEMPTS this route so the redirect does not loop, and an exemption is not a restriction:
 * without the guard this is an authenticated password-SET endpoint that asks for nothing.
 * Anybody holding a live session — a stolen cookie, or one obtained through a door that
 * never asks for a password — could set a password of their own choosing and then use it
 * to satisfy every step-up gate on the account.
 */
final readonly class ChangePasswordController extends PageController
{
    public function edit(CurrentUser $me): Response
    {
        return $this->page('auth/change-password', 'Choose a new password', [
            // For the password manager, so it updates the credential it already holds
            // rather than saving a second one nobody asked for.
            'email' => $me->email(),
        ]);
    }

    public function update(
        ChangePasswordRequest $request,
        Subjects $subjects,
        AdminPasswords $admin,
        PasswordExpiry $expiry,
        CurrentUser $me,
    ): RedirectResponse {
        /*
         * Only somebody the middleware is actually HOLDING here may set a password here.
         *
         * Both of its reasons count — an administrator imposed the change, or the tenant's
         * maximum age ran out. Guarding on the administrative one alone locks out everyone
         * held by rotation: the middleware sends them to this page and the page refuses to
         * let them leave it. Two conditions, one hold, and the guard has to mirror the
         * hold exactly.
         */
        abort_unless(
            $admin->requiresChange($me->id()) || $expiry->hasExpired($me->id()),
            403,
        );

        $subjects->setPassword($me->id(), $request->password());

        // Only NOW is the requirement satisfied. Clearing it before the write would
        // release the hold on a password the policy might still refuse.
        $admin->clear($me->id());

        return to_route('dashboard')->with('status', 'Your password has been updated.');
    }
}
