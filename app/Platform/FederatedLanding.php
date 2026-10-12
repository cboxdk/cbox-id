<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\AccountLinking\ConnectIntent;
use App\Platform\AccountLinking\FederationFailure;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

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

        // The intent has to be one a SUBJECT can serve. A tenant host also carries the
        // environment ADMIN console, whose refusals write the same `url.intended`, and
        // sending an end user to `/admin/...` bounces them back here with the intent
        // rewritten — the loop an administrator hit from the other direction.
        return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'));
    }

    /**
     * Where an inbound federated sign-in lands when it FAILS — and the sentence it lands with.
     *
     * Kept beside {@see land()} rather than inlined at each callback, which is where it
     * started: both of them wrote `route('login')` on their error branch while `/login`
     * was withheld from the platform root, so on that host every failure rendered a bare
     * 404. That is a worse failure than a lockout — it happens AFTER a successful
     * authentication at the IdP, so the person has every reason to believe SSO worked and
     * no way to learn it did not.
     *
     * THE MESSAGE IS ON INERTIA'S FLASH CHANNEL, as `signInProblem`, and the sign-in page
     * draws it as an alert. It used to go into the validation bag under `email`, on the
     * theory that the email field renders it. It did not: the page reads its errors from
     * its own form object, which is empty on a fresh load, so every sentence written here
     * was silently dropped — the production report was an owner signing in with Google,
     * being bounced to `/login`, and seeing nothing at all. The flash is one-shot (not
     * written into the history entry, so Back does not resurrect it) and it is what the
     * tests now assert on, through the real page, for every failure branch.
     *
     * SOMEBODY ALREADY SIGNED IN is not sent to `/login`, which the guest guard would bounce
     * to the dashboard with the message lost. They are connecting a provider from My
     * account, or adding a second account, and My account is where the answer belongs —
     * on the console's toast, which every console layout draws.
     *
     * @param  array<string, string>  $replace  `provider` — the name the person clicked — and any other placeholder the sentence uses
     */
    public function failed(FederationFailure $reason, array $replace = []): RedirectResponse
    {
        // A connect that failed is over: the intent must not linger to be claimed by the
        // next, unrelated callback through the same connection.
        ConnectIntent::forget();

        // The console is English; the sign-in page speaks the visitor's language.
        if (session()->has(PlatformAuth::SESSION_KEY)) {
            return redirect()->route('account')->with('error', $reason->message($replace, 'en'));
        }

        Inertia::flash('signInProblem', $reason->message($replace));

        return redirect()->route('login');
    }
}
