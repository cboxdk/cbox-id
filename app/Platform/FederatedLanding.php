<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an inbound federated sign-in lands once the assertion has been verified.
 *
 * Both SSO callbacks (SAML ACS, OIDC) reach the same fork, and it is a plane fork rather
 * than a credential fork. Account members are ordinary subjects in the platform-root
 * environment, so an account's SSO connection is an ordinary connection on the account's
 * organization there — the protocol half needs nothing account-specific. What differs is
 * only where a verified identity is admitted:
 *
 *  - On a TENANT host the assertion signs a subject into that tenant's console.
 *  - On the ACCOUNT host it signs an account MEMBER into the workspace, and does so only
 *    if the subject actually carries an account membership. A subject with no membership
 *    is refused outright rather than being handed a subject session — the account host
 *    must never mint a credential for a plane it does not serve.
 *
 * See docs/core-concepts/unified-account-identity.md.
 */
final class FederatedLanding
{
    public function __construct(
        private readonly AccountAuth $accounts,
        private readonly PlatformAuth $platform,
        private readonly PlaneResolver $planes,
    ) {}

    public function land(Request $request, Session $session): RedirectResponse
    {
        if (! $this->planes->onAccountPlane()) {
            // adopt() turns an already-started framework session into the browser
            // cookie, rotating the session id against fixation.
            $this->platform->adopt($request, $session);

            return redirect()->intended(route('dashboard'));
        }

        return match ($this->accounts->adoptSubject($session->user_id)) {
            AttemptOutcome::Ok => redirect()->intended(route('workspace.home')),
            // A second factor enrolled on the account plane still stands: federating in
            // must not be a way around a factor the member deliberately added.
            AttemptOutcome::Mfa => redirect()->route('workspace.login.mfa'),
            default => redirect()->route('workspace.login')->withErrors([
                'email' => 'That single sign-on identity is not a member of any workspace.',
            ]),
        };
    }
}
