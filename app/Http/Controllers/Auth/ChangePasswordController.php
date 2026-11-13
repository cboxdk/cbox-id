<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Middleware\Authenticate;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\Lifecycle\EndOtherSessions;
use App\Platform\Surfaces\ReturnUrl;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function edit(Request $request, CurrentUser $me, ReturnUrl $returns): Response
    {
        // The console sent them here in the middle of opening a page (`return_to`): kept,
        // validated, for the redirect that ends the change.
        $returns->remember($request);

        return $this->page('auth/change-password', __('auth.change_password.title'), [
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
        EndOtherSessions $sessions,
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

        // A changed password ends every other session and the apps they signed in to, as it
        // does on the account page: a session opened with the old password must not outlive
        // it. The one making the change stays ({@see EndOtherSessions}).
        $sessions->handle($me->id(), $me->session()?->id);

        // On to where they were going when the hold caught them: an app's authorization
        // (`/oauth/authorize` holds for this too, and waits in the session), else the
        // console. It used to be the console always, and the app never heard back.
        // The console's page comes back through its own start in login-host mode
        // ({@see \App\Http\Middleware\EnterConsoleThroughStart}).
        return redirect()->to(IntendedUrl::pullForSubject() ?? app(ReturnUrl::class)->pull($request) ?? route('dashboard'))
            ->with('status', __('console.your_password_has_been_updated_cc244a90'));
    }
}
