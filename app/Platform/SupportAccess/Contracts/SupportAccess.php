<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess\Contracts;

use App\Platform\OAuth\PendingAuthorization;
use App\Platform\SupportAccess\Exceptions\SupportRequestRefused;
use App\Platform\SupportAccess\ValueObjects\ActiveSupportSession;
use App\Platform\SupportAccess\ValueObjects\SupportApp;
use App\Platform\SupportAccess\ValueObjects\SupportSignInRequest;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Exceptions\SupportSessionRefused;

/**
 * SUPPORT ACCESS FROM THE ENVIRONMENT CONSOLE — "sign in to <app> as <user>".
 *
 * An environment administrator starts a support session through the framework's
 * {@see SupportSessions} (as an environment administrator: the console asserts that, the
 * framework cannot know it), and their browser is handed to the app, which starts its own
 * sign-in. The authorization endpoint recognises the browser's open session for that app
 * and answers with a code minted for it — so the app completes an ordinary code exchange
 * and receives tokens carrying `act`, never a refresh token, and nothing that outlives
 * the session.
 *
 * Why the app starts the sign-in rather than the console minting the code: a code is bound
 * to the app's own PKCE challenge, which only the app holds. The framework's `begin()`
 * mints a first code only when handed that challenge, and `issueCode()` exists for exactly
 * this — the app's authorization request arriving while the session is open.
 */
interface SupportAccess
{
    /**
     * The apps a support session can reach: first-party, owned by the environment, using
     * the authorization-code grant, with a web address the console can send a browser to.
     * By name.
     *
     * @return list<SupportApp>
     */
    public function eligibleApps(): array;

    /** Whether ONE app is eligible — asked of the database, never of a page. */
    public function isEligible(string $clientId): bool;

    /** The longest session the console offers, in minutes: the configured maximum. */
    public function maxMinutes(): int;

    /**
     * Start a session and hand THIS browser to the app. Returns the address to send the
     * browser to — the app's own entry point, which starts its sign-in.
     *
     * @throws SupportSessionRefused naming the check that failed
     */
    public function start(SupportSignInRequest $request): string;

    /**
     * The support sessions open right now for one person, newest first.
     *
     * @return list<ActiveSupportSession>
     */
    public function activeForUser(string $userId): array;

    /**
     * The support sessions open right now in one organization, newest first.
     *
     * @return list<ActiveSupportSession>
     */
    public function activeForOrganization(string $organizationId): array;

    /**
     * End a session now — its codes die and every token it minted is revoked. False when
     * no such session exists in this environment (and nothing was ended).
     */
    public function end(string $sessionId, string $endedBy): bool;

    /**
     * The code for an authorization request arriving from an app this browser holds an
     * open support session for — or null, and the request is an ordinary one.
     *
     * Only for the administrator who started it, still signed in to this environment's
     * console: a browser whose administrator has signed out, or been revoked, or become
     * somebody else, gets nothing from a session another person opened.
     *
     * The session is one person in one organization. A request that NAMES an organization
     * (`organization`, from the pushed request alone when there is one) must name that
     * one, and `prompt=create_organization` cannot be answered at all; either is refused
     * rather than answered with the session's organization or passed to a sign-in page.
     * `organization_hint` and `prompt=select_organization` are answered with the session's
     * organization: the administrator already chose it.
     *
     * @throws SupportRequestRefused
     */
    public function codeFor(PendingAuthorization $authorization): ?string;
}
