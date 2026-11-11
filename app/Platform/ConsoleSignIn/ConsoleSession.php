<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\PlatformAuth;
use App\Platform\SignInLineage;
use App\Platform\SsoMandates;
use Cbox\Id\ExternalActions\Exceptions\ActionDenied;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\OAuthServer\Support\SessionIdentifier;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * THE CONSOLE'S OWN SESSION ROW, started from what the login host said and tied to the
 * SSO session it came from.
 *
 * `sub` is the person's row: the console and the login host are the same environment of
 * the same deployment, so nothing is provisioned or linked, and a `sub` that is no active
 * person here signs nobody in.
 *
 * A NEW ROW EVERY TIME the browser returns, a step-up included. A re-authentication at
 * the login host is a new SSO session, and the one before it may be ended a moment later;
 * a console row that kept its tie to the old one would be ended with it
 * ({@see ConsoleBackchannelLogout}) just after the person proved who they are. So each
 * row has exactly one parent, the SSO session named by the ID token's `sid`, recorded with
 * {@see SignInLineage::link()}, and the row this browser held before is ended.
 *
 * The tie is what makes sign-out whole in both directions: signing out of the console ends
 * the family ({@see PlatformAuth::logoutAll()}), and the login host ending the SSO session
 * ends the console rows handed down from it.
 */
final class ConsoleSession
{
    /** How many of a person's newest session rows are searched for a `sid`. */
    private const int SID_SEARCH = 200;

    public function __construct(
        private readonly PlatformRoot $root,
        private readonly Subjects $subjects,
        private readonly SessionManager $sessions,
        private readonly Memberships $memberships,
        private readonly SignInLineage $lineage,
        private readonly PlatformAuth $auth,
    ) {}

    /**
     * Start the console session for a verified identity.
     *
     * @throws ConsoleSignInFailed
     */
    public function establish(Request $request, ConsoleIdentity $identity): Session
    {
        $session = $this->root->run(function () use ($request, $identity): Session {
            $subject = $this->subjects->find($identity->subjectId);

            if ($subject === null || ! ($subject->admitsSignIn() ?? $this->subjects->isActive($subject->id))) {
                throw ConsoleSignInFailed::because('unknown_subject');
            }

            // The SSO session must still be a live sign-in of this person: a code minted
            // before a sign-out and redeemed after it starts nothing.
            $parent = $identity->sid === null ? null : $this->sessionWithSid($subject->id, $identity->sid);
            $live = $parent === null ? null : $this->sessions->active($parent->id);

            if ($live === null || $live->user_id !== $subject->id) {
                throw ConsoleSignInFailed::because('sso_session_ended');
            }

            try {
                $session = $this->sessions->start(
                    $subject->id,
                    $this->organizationOf($identity),
                    $identity->amr,
                    $request->ip(),
                    $request->userAgent(),
                );
            } catch (ActionDenied) {
                throw ConsoleSignInFailed::because('sign_in_denied');
            }

            $this->lineage->link($session->id, [$live->id]);

            return $session;
        });

        if (! $session instanceof Session) {
            throw ConsoleSignInFailed::because('no_default_environment');
        }

        // The organization this browser was in, when it is the same person coming back: a
        // step-up must not move them. Whether the new session may be in it is the gate's
        // question on the next request, as for any other organization.
        $held = $this->heldOrganization($request, $identity->subjectId);

        $this->auth->relyOn($request, $session);

        if ($held !== null) {
            $request->session()->put(PlatformAuth::ORG_KEY, $held);
        }

        return $session;
    }

    /**
     * The session row of this person whose OIDC `sid` is `$sid`, live or ended, or null.
     *
     * `sid` is a digest of the row's id ({@see SessionIdentifier}), so it is matched by
     * computing it for the person's own rows; nothing maps it back.
     */
    public function sessionWithSid(string $subjectId, string $sid): ?Session
    {
        /** @var Collection<int, Session> $rows */
        $rows = Session::query()
            ->where('user_id', $subjectId)
            ->latest('id')
            ->limit(self::SID_SEARCH)
            ->get();

        return $rows->first(static fn (Session $row): bool => hash_equals(SessionIdentifier::sid($row->id), $sid));
    }

    /**
     * The organization the row is IN: the one the sign-in at the login host is in (`org`),
     * so the row says the same as the SSO session it came from. An organization that
     * requires its own identity provider is entered only on a session that is in it and
     * came through that provider ({@see SsoMandates}); `amr` and this are
     * the two facts that rule reads. Else the person's first membership, as at any sign-in.
     */
    private function organizationOf(ConsoleIdentity $identity): ?string
    {
        if ($identity->organizationId !== null
            && $this->memberships->of($identity->organizationId, $identity->subjectId)?->status === MembershipStatus::Active) {
            return $identity->organizationId;
        }

        $first = $this->memberships->forUser($identity->subjectId)->firstWhere('status', MembershipStatus::Active)?->organization_id;

        return is_string($first) ? $first : null;
    }

    /** The organization this browser's console was in, if it was this person's. */
    private function heldOrganization(Request $request, string $subjectId): ?string
    {
        $session = $request->session();
        $held = $session->get(PlatformAuth::ORG_KEY);

        return $session->get(PlatformAuth::ACTIVE_KEY) === $subjectId && is_string($held) && $held !== '' ? $held : null;
    }
}
