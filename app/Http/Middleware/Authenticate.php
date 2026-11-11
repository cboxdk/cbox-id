<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\ConsoleSignIn\ConsoleSignIn;
use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\OperatorMfa;
use App\Platform\OrganizationAccess;
use App\Platform\PlatformAuth;
use App\Platform\SessionMetadata;
use App\Platform\SignedInPage;
use App\Platform\SsoMandates;
use App\Platform\Surfaces\ReturnUrl;
use App\Platform\Surfaces\SessionSurface;
use App\Platform\Surfaces\Surfaces;
use App\SignalsData\IdentityCapture;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the platform session held in the browser cookie into the current
 * subject + organization. A missing or revoked session bounces to login — the
 * framework's session store, not the cookie, is authoritative.
 */
final class Authenticate
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Subjects $subjects,
        private readonly Organizations $organizations,
        private readonly Memberships $memberships,
        private readonly CurrentUser $current,
        private readonly AdminPasswords $adminPasswords,
        private readonly PasswordExpiry $passwordExpiry,
        private readonly MfaMandate $mfaMandate,
        private readonly SessionSurface $surface,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string|null  $mode  pass `optional` to RESOLVE the subject without requiring one
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // `optional`: populate CurrentUser when there IS a valid session, and continue as
        // a guest when there is not — for endpoints that must answer an unauthenticated
        // caller themselves rather than being redirected away.
        //
        // /oauth/authorize is the case: OIDC Core §3.1.2.6 requires prompt=none to return
        // error=login_required TO THE CLIENT. Moving that route out of this middleware
        // entirely was WRONG — CurrentUser is populated ONLY here, so check() became
        // permanently false and no authorization code could be issued at all. Optional
        // mode keeps every re-check below (revoked session, deactivated subject,
        // suspended org) instead of the component re-implementing them badly.
        //
        // Optional means "a subject is not REQUIRED", never "the rules are relaxed".
        // The two policy holds below used to sit behind `! $optional`, so an
        // organization's own sign-in rules were enforced on the console and skipped on
        // the endpoint that mints tokens — a member held out of the console for not
        // enrolling a second factor could still authorize an app and walk away with a
        // refresh token. The tell was already in the code: exemptFromHolds() carves out
        // prompt=none for the authorize endpoint specifically, a carve-out that could
        // never be reached from a route running in this mode.
        $optional = $mode === 'optional';
        // A session minted on another surface is nobody's here (login-host mode).
        $sessionId = $this->surface->admits($request)
            ? $request->session()->get(PlatformAuth::SESSION_KEY)
            : null;

        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            /*
             * ONLY THIS HOST'S SESSION. The environment console is on the app host now, in
             * that host's own session, so forgetting a dead end-user key on a tenant's host
             * costs the console nothing. It once did: a tenant page that resolved a
             * subject — /oauth/authorize, say — signed the administrator out of the console
             * that shared its host.
             */
            $request->session()->forget(PlatformAuth::SESSION_KEY);

            $this->current->clear();

            if ($optional) {
                return $next($request);
            }

            return $this->refuse($request);
        }

        $subject = $this->subjects->find($session->user_id);

        // find() still returns a deactivated/suspended subject, so a live cookie
        // would otherwise outlive the account being disabled. Re-check per request
        // (as ConsoleScope::operator() does for operator authority) and refuse an
        // inactive subject.
        if ($subject === null || ! $this->stillAdmitted($subject)) {
            $request->session()->forget(PlatformAuth::SESSION_KEY);
            $this->current->clear();

            if ($optional) {
                return $next($request);
            }

            // In login-host mode the console has no `/login`: its door is the login host.
            return ($door = app(ConsoleSignIn::class))->active($request)
                ? $door->refuse($request)
                : redirect()->guest(route('login'));
        }

        // Resolve the active org — but ONLY honour one the subject is a member of.
        // A revoked selection must not silently retarget an in-flight write to
        // another organization. Only an unscoped session may choose an initial org.
        $selectedOrgId = $request->session()->get(PlatformAuth::ORG_KEY);
        $requestedOrgId = $selectedOrgId ?? $session->organization_id;

        $organization = null;
        $role = null;

        /*
         * AND ONLY ONE THIS SESSION MAY ENTER. With SSO mandates per organization
         * ({@see SsoMandates::perOrganization()}), an organization that requires SSO is
         * bound only to a session that came through its own connection. A session that
         * did not is simply not IN that organization: every surface that reads the acting
         * organization from here (console pages, the authorize endpoint, the SAML IdP,
         * device approval) then acts in another of the person's organizations or in none,
         * and their own account pages stay reachable. The doors that NAME an organization
         * (its console address, the `organization` parameter) send the person through its
         * connection instead.
         */
        $mandates = app(SsoMandates::class);

        if (is_string($requestedOrgId)
            && ($membership = $this->memberships->of($requestedOrgId, $subject->id)) !== null
            && $membership->status === MembershipStatus::Active) {
            if ($mandates->admits($session, $requestedOrgId)) {
                $organization = $this->organizations->find($requestedOrgId);
                $role = $membership->role;
            } elseif ($selectedOrgId === null) {
                // Nobody chose it: it is only where the session was started. Start
                // somewhere this session may be instead of nowhere.
                $requestedOrgId = null;
            }
        }

        if ($requestedOrgId === null) {
            $fallbackOrgId = $this->memberships->forUser($subject->id)
                ->first(fn ($candidate): bool => $candidate->status === MembershipStatus::Active
                    && $mandates->admits($session, $candidate->organization_id))?->organization_id;

            if (is_string($fallbackOrgId)) {
                $organization = $this->organizations->find($fallbackOrgId);
                $role = $this->memberships->of($fallbackOrgId, $subject->id)?->role;
                $request->session()->put(PlatformAuth::ORG_KEY, $fallbackOrgId);
            }
        }

        // Deny-by-default on a tenant that is no longer live: an operator can suspend an
        // org and an environment admin can delete one, and either must take effect on
        // the very next request — no console access, no token minting — not just at the
        // next login. Both statuses route through OrganizationAccess so no enforcement
        // point can drift out of step with the others again.
        $refusal = OrganizationAccess::refusalPhrase($organization?->status);
        if ($organization !== null && $refusal !== null) {
            abort(403, 'This organization has been '.$refusal.'. Contact your platform operator.');
        }

        // Older sessions can fill their own missing details when that device returns.
        SessionMetadata::captureMissing($session, $request);

        $this->current->set($subject, $session, $organization, $role);

        // A signed-in page is drawn for this session only: kept out of the browser's caches,
        // and its Inertia page state encrypted in history, so that after signing out the
        // Back button asks the server again instead of redrawing it ({@see SignedInPage}).
        // Existing weak sessions must not use the account/enrolment exemptions to
        // replace an already enrolled factor. OAuth resolves prompt/PAR itself below.
        $requiresEnrolment = $this->mfaMandate->requiresEnrolment($subject->id);

        if (! $request->routeIs('oauth.authorize', 'oauth.authorize.*', 'logout', 'logout.*', 'console.auth.*')
            && ! $requiresEnrolment
            && app(MfaVerification::class)->requiresVerification($subject->id, array_values($session->amr))) {
            $this->current->clear();

            // The second factor is asked for where the person signs in. On the console of
            // login-host mode that is the login host, reached through the console's door.
            if (($door = app(ConsoleSignIn::class))->active($request)) {
                return $optional ? $next($request) : redirect()->to($door->startUrl(['mfa' => 1]));
            }

            return redirect()->to(app(PlatformAuth::class)->challengeSession($request, $session));
        }

        if ($this->exemptFromHolds($request)) {
            app(IdentityCapture::class)->activity($session, $request);

            return SignedInPage::serve($request, $next);
        }

        if ($this->mustChangePassword($request, $subject->id)) {
            // A person's own security pages live with the SSO session: on the login host
            // in login-host mode, where the console does not serve them.
            // The page they were opening goes along (`return_to`), so the change ends there
            // and not on the console's front page.
            return app(ConsoleSignIn::class)->active($request)
                ? redirect()->away(app(Surfaces::class)->loginUrl('/password/change', $this->wayBack($request)))
                : redirect()->route('password.change');
        }

        // A tenant that requires a second factor cannot enforce it by turning people
        // away — that locks out precisely the people who still need to enrol. Hold them
        // on the security page instead, which is where enrolment lives.
        //
        // THE SUBMITS TOO, for the reason `exemptFromHolds()` gives: exempting only the
        // `account` and `sudo` screens bounced every enrolment POST — the authenticator,
        // the phone number, the passkey, the password confirmation in front of them — back
        // to the page that held them, so a person told to enrol could not. An
        // administrator asked for a stronger factor because SMS was all they had (see
        // `SmsFactors::needsStrongerFactor()`) is held here and must be able to add one.
        if (! $request->routeIs('account', 'account.mfa.*', 'sudo', 'sudo.*', 'passkeys.register', 'passkeys.register.*')
            && $requiresEnrolment
        ) {
            // In login-host mode the security page is on the login host, and the page they
            // were opening goes along so "Back to …" there leads to it.
            $account = app(ConsoleSignIn::class)->active($request)
                ? redirect()->away(app(Surfaces::class)->loginUrl('/settings', $this->wayBack($request)))
                : redirect()->route('account');

            return $account
                ->with('status', $this->mfaMandate instanceof OperatorMfa && $this->mfaMandate->requiresPasskey($subject->id)
                    ? 'Platform operators sign in with a passkey or security key. Add one under Security to continue.'
                    : 'Your organization requires two-factor authentication. Set it up to continue.');
        }

        app(IdentityCapture::class)->activity($session, $request);

        return SignedInPage::serve($request, $next);
    }

    /**
     * The console page a held request was opening, as the `return_to` the login host's
     * pages take ({@see ReturnUrl}, which validates it there). Only a page: a write is not
     * replayed by a link, so it names nothing and the person lands on the console's default.
     *
     * @return array<string, string>
     */
    private function wayBack(Request $request): array
    {
        return $request->isMethod('GET') ? [ReturnUrl::PARAMETER => $request->fullUrl()] : [];
    }

    /**
     * The standing re-check above, answered from the row we are already holding when the
     * resolver told us.
     *
     * The check itself is unchanged and non-negotiable — it is what makes deactivating an
     * account take effect on the very next request rather than at its owner's next
     * sign-in. What changed is the cost: `find()` and `isActive()` were two separate
     * `select * from users where id = ?` against the same row, on every authenticated page
     * AND on every Livewire round trip, because {@see Subject} carried no status for the
     * second question to be answered from.
     *
     * A resolver that does not say still gets asked. {@see Subject::admitsSignIn()}
     * returns null for a host-bound {@see Subjects} implementation written before the
     * status existed, and null falls through to the contract — so an unaware resolver
     * keeps paying the query and keeps being right, rather than having "active" assumed
     * about its deactivated accounts.
     */
    private function stillAdmitted(Subject $subject): bool
    {
        return $subject->admitsSignIn() ?? $this->subjects->isActive($subject->id);
    }

    /**
     * Routes that must never be held, whatever a policy says.
     *
     * The change page and the security page are how a hold is satisfied; logout is how
     * someone leaves. Holding any of them turns a requirement into a lock-in with no way
     * forward and no way out.
     *
     * THE LIST IS SHORTER THAN IT WAS, and that is the point rather than a trim. It also
     * carried the account plane's own change page, its logout, and the landing it sent a
     * member-less person to — because that plane had a second copy of each, so a hold
     * whose destination was itself held became a two-hop cycle: held to the security page,
     * turned around to the landing, held again, unbounded. There are no second copies now.
     * The MFA hold's destination is `/account`, which the mandate check above excludes by
     * name; the temporary-password hold's is `password.change`, which is exempt here; and
     * an Identity platform page a person cannot use sends them on to a console root that
     * is held exactly once and then satisfied or not. Nothing in that chain is a cycle,
     * and nothing in it needs a carve-out.
     *
     * `prompt=none` is exempt for a different reason: OIDC Core §3.1.2.6 requires the
     * CLIENT to be answered with `error=login_required` rather than a user agent being
     * redirected after being told not to interact. The authorize endpoint makes that
     * call; a redirect from here would pre-empt it.
     */
    private function exemptFromHolds(Request $request): bool
    {
        // The authorize endpoint enforces these itself, in the consent component.
        //
        // It has to: `prompt` arrives by query string, by PAR payload or in a POST body,
        // and only the component resolves all three. Reading `$request->query('prompt')`
        // here meant a silent-renew iframe using PAR or POST was redirected to the
        // password-change page instead of being answered with an OIDC error — and under
        // `require_par`, PAR is the ONLY legal way to send prompt=none, so the carve-out
        // was dead there entirely.
        /*
         * THE SUBMIT, NOT ONLY THE SCREEN.
         *
         * Each of these holds is ended by an action on the page it holds you on, and each
         * of those actions is now its OWN route — `password.change.update`. Exempting only the GET names leaves the hold unsatisfiable: the
         * form posts, this bounces it back to the form, and the person is on a page whose
         * buttons cannot work. There is no way out of that loop, including signing out on
         * a deployment where `logout` were ever dropped from this list.
         *
         * It read correctly while every action was a Livewire POST to `/livewire/update`
         * carrying its component, because the route name the middleware saw was the
         * PAGE's. One screen is several routes now, and a name-based allowlist has to name
         * all of them — which is why these are matched with a wildcard rather than spelled
         * out one at a time: the next action added to one of these screens is exempt by
         * construction rather than by somebody remembering this list exists.
         */
        return $request->routeIs(
            'password.change', 'password.change.*',
            'logout',
            // `oauth.authorize.*` covers the approve and deny endpoints along with the
            // screen itself: they are the same act, split across three requests by the
            // port, and a hold that let somebody reach the page but not answer it would
            // strand the relying party's callback rather than protect anything.
            'oauth.authorize', 'oauth.authorize.*',
            // The console's door in login-host mode: how a hold that sends somebody to
            // the login host is come back from.
            'console.auth.*',
        );
    }

    /**
     * A temporary password carries a standing requirement to replace it. Enforcing that
     * at the point of SIGN-IN would only cover the password door — an administrator can
     * hand out a temporary password to someone who then arrives by magic link, SSO or an
     * already-open session, and the requirement would evaporate. Holding every
     * authenticated request instead means the only way past it is to satisfy it.
     *
     * Before this, `requiresChange()` was read in exactly one place: to render a line of
     * prose on the user's console page. "Temporary" described nothing, and a temporary
     * password with no expiry was simply a permanent one the administrator also knew.
     */
    private function mustChangePassword(Request $request, string $subjectId): bool
    {
        // Two different reasons to owe a change, one hold: an administrator imposed it,
        // or the tenant's maxAgeDays has run out. Rotation is only a policy if something
        // acts on it, and acting on it at sign-in alone would let an already-open session
        // outlive the rotation it was supposed to trigger.
        return $this->adminPasswords->requiresChange($subjectId)
            || $this->passwordExpiry->hasExpired($subjectId);
    }

    /**
     * Send an unauthenticated caller to sign in — in a way the CALLER can act on.
     *
     * A 302 is right for a browser navigation and useless to a Livewire request. The
     * console runs on Livewire, its fetch FOLLOWS the redirect, and what comes back is a
     * 200 carrying the login page's HTML — not a Livewire response, not an error status.
     * Livewire cannot parse it and there is no failure to report, so clicking around a
     * console whose session had expired did nothing at all: no error, no prompt, no
     * redirect. The only clue was that the page had quietly stopped working.
     *
     * It reached that state because two clocks disagree. Laravel's session cookie lives
     * for `SESSION_LIFETIME`, and the platform session record expires on its own idle
     * window (`cbox-id.sessions.idle_minutes`) — so the cookie, and therefore the CSRF
     * token, is still valid when the record is already gone. With a valid token there is
     * no 419 either, which is the one status the console's error surface already handled.
     *
     * 401 with a JSON body naming where to go: the front end turns it into "your session
     * expired, sign in again" and sends them to a login that still returns them to where
     * they were, because guest() has stashed it either way.
     */
    private function refuse(Request $request): Response
    {
        // In login-host mode the console has no `/login`: an unauthenticated request goes
        // to the login host as the console's own client, and comes back to this page.
        if (($door = app(ConsoleSignIn::class))->active($request)) {
            return $door->refuse($request);
        }

        // guest() stashes the intended URL, so a user sent here mid-flow (e.g.
        // /oauth/authorize?…) is returned to complete it after logging in.
        $redirect = redirect()->guest(route('login'));

        // On its way to open an environment's console: stamped, so the sign-in that follows
        // takes them there — and only the sign-in that follows ({@see IntendedUrl::pullForSubject()}).
        if ($request->routeIs('environment.open')) {
            IntendedUrl::rememberEnvironmentOpen();
        }

        // An XHR — an Inertia visit or a plain fetch — cannot follow a cross-page redirect
        // into a sign-in form, so it is told where to go instead. This used to name
        // Livewire's own request check first; every such request is an ordinary
        // `expectsJson()` one now.
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => 'Your session has expired.',
                'redirect' => route('login'),
            ], 401);
        }

        return $redirect;
    }
}
