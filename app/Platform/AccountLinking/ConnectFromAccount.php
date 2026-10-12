<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\FederatedLanding;
use App\Platform\PlatformAuth;
use App\Platform\SsoStart;
use App\Platform\Sudo;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Http\RedirectResponse;

/**
 * MY ACCOUNT › SIGN-IN METHODS › CONNECT — for the providers an environment (or an
 * organization) set up as connections: the same Google that is on the sign-in page.
 *
 * The deployment operator's own providers have had a connect flow of their own for a long
 * time (`/settings/connect/{provider}`), with its own redirect URI. A connection has ONE
 * redirect URI, the one its administrator registered with the provider, so connecting
 * through it has to ride the sign-in callback — {@see ConnectIntent} is how that callback
 * knows the difference.
 *
 * The proof that the account is the person's is the one My account already demands for a
 * new way in: they are signed in, and the start sits behind a fresh step-up (`sudo`) on its
 * route, as connecting an operator provider and enrolling a passkey always have. The finish
 * asks again — still signed in, as the same person, in the same session, inside the same
 * step-up window — because ten minutes at a provider is long enough for any of those to
 * have changed. No address has to match: the person has proved both sides.
 */
final readonly class ConnectFromAccount
{
    public function __construct(
        private Subjects $subjects,
        private SessionManager $sessions,
        private FederatedLanding $landing,
        private AccountLinking $linking,
        private Sudo $sudo,
    ) {}

    /** Say "this trip is a connect", and send the browser to the provider. */
    public function start(Connection $connection, string $subjectId, string $sessionId): RedirectResponse
    {
        ConnectIntent::start($connection->id, $subjectId, $sessionId);

        return redirect()->to(SsoStart::url($connection));
    }

    /**
     * The provider vouched for somebody, and this browser had said it was connecting:
     * attach that identity to the account still signed in here.
     *
     * @param  array{subject_id: string, session_id: string}  $intent
     */
    public function finish(array $intent, Connection $connection, FederatedPrincipal $principal): RedirectResponse
    {
        $label = ['provider' => $connection->name];
        $current = session()->get(PlatformAuth::SESSION_KEY);
        $session = is_string($current) ? $this->sessions->active($current) : null;

        if ($session === null
            || ! hash_equals($intent['session_id'], $session->id)
            || $session->user_id !== $intent['subject_id']
            || ! $this->sudo->confirmed()
        ) {
            return $this->landing->failed(FederationFailure::ConnectSessionChanged, $label);
        }

        try {
            $this->subjects->link($session->user_id, $principal);
        } catch (IdentityAlreadyLinked) {
            return $this->landing->failed(FederationFailure::AlreadyLinked, $label);
        }

        $this->linking->recordLinked($session->user_id, $principal, (string) ($connection->provider ?? 'sso'), LinkProof::SignedIn, 'account');

        return redirect()->route('account')->with('status', $connection->name.' is connected. You can sign in with it from now on.');
    }
}
