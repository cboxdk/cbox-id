<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an inbound federated sign-in lands once the assertion has been verified.
 *
 * Both SSO callbacks (SAML ACS, OIDC) reach this, and it used to fork on the plane: a
 * tenant host signed the subject into the tenant console, the account host signed an
 * account MEMBER into the workspace, and a subject with no membership was refused there
 * outright. The fork is gone with the plane it forked on. Account members are ordinary
 * subjects in the platform-root environment and an account's SSO connection is an
 * ordinary connection on the account's organization there, so the protocol half never
 * needed anything account-specific — and now neither does the landing: one console, one
 * destination, and what the person owns decides what is in it.
 *
 * The refusal that went with the fork is gone for the same reason and is not a hole. It
 * said "a subject with no account membership must not be admitted HERE", which was a
 * statement about a plane that no longer exists; the root is a tenant, and a subject of
 * it signing in is an ordinary sign-in. What their organization owns is asked by
 * {@see Console\ConsoleScope::accountRole()} on every page that depends on it.
 *
 * See docs/core-concepts/unified-identity.md.
 */
final class FederatedLanding
{
    public function __construct(private readonly PlatformAuth $platform) {}

    public function land(Request $request, Session $session): RedirectResponse
    {
        // adopt() turns an already-started framework session into the browser cookie,
        // rotating the session id against fixation.
        $this->platform->adopt($request, $session);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Where an inbound federated sign-in lands when it FAILS.
     *
     * Kept beside {@see land()} rather than inlined at each callback, which is where it
     * started: both of them wrote `route('login')` on their error branch while `/login`
     * was withheld from the platform root, so on that host every failure rendered a bare
     * 404. That is a worse failure than a lockout — it happens AFTER a successful
     * authentication at the IdP, so the person has every reason to believe SSO worked and
     * no way to learn it did not.
     *
     * `email` is the error-bag key on purpose: it is the field the sign-in screen renders.
     * The callbacks previously used `identifier`, which no view reads, so the message was
     * silently dropped and the user was returned to a blank form with no indication of
     * what had gone wrong.
     */
    public function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
