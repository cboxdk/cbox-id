<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\Request;

/**
 * Bridges the framework's session store to the browser. A logged-in browser
 * holds only the platform session id in Laravel's encrypted, http-only session
 * cookie — never a token. The framework session stays the source of truth
 * (revocable, records how the user authenticated).
 */
final class PlatformAuth
{
    public const SESSION_KEY = 'cbox.session';

    public const ORG_KEY = 'cbox.org';

    private const MFA_PENDING_KEY = 'cbox.mfa_pending';

    private const PENDING_LINK_KEY = 'cbox.pending_link';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Subjects $subjects,
        private readonly Memberships $memberships,
        private readonly Mfa $mfa,
    ) {}

    /**
     * Attempt a password login. Returns 'ok', 'mfa', or 'invalid'. On 'mfa' the
     * subject is held pending a second factor — no session is started yet.
     */
    public function attemptPassword(Request $request, string $email, string $password): string
    {
        $subject = $this->subjects->findByEmail($email);

        if ($subject === null || ! $this->subjects->verifyPassword($subject->id, $password)) {
            return 'invalid';
        }

        if ($this->mfa->hasConfirmedTotp($subject->id)) {
            session()->put(self::MFA_PENDING_KEY, $subject->id);

            return 'mfa';
        }

        $this->establish($request, $subject->id, ['pwd']);

        return 'ok';
    }

    public function pendingMfaSubject(Request $request): ?string
    {
        $id = session()->get(self::MFA_PENDING_KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * Complete a pending MFA challenge with a TOTP code.
     */
    public function completeMfa(Request $request, string $code): bool
    {
        $subjectId = $this->pendingMfaSubject($request);

        if ($subjectId === null || ! $this->mfa->verifyTotp($subjectId, $code)) {
            return false;
        }

        session()->forget(self::MFA_PENDING_KEY);
        $this->establish($request, $subjectId, ['pwd', 'mfa']);

        return true;
    }

    /**
     * Establish a browser session for an already-authenticated subject (also
     * used after magic-link and passkey login).
     *
     * @param  list<string>  $amr  how the subject authenticated
     */
    public function establish(Request $request, string $subjectId, array $amr): void
    {
        $organizationId = $this->memberships->forUser($subjectId)->value('organization_id');
        $organizationId = is_string($organizationId) ? $organizationId : null;

        $session = $this->sessions->start($subjectId, $organizationId, $amr);

        session()->put(self::SESSION_KEY, $session->id);

        if ($organizationId !== null) {
            session()->put(self::ORG_KEY, $organizationId);
        }

        // Rotate the id to defeat session fixation.
        session()->regenerate();

        $this->applyPendingLink($subjectId);
    }

    /**
     * Adopt a framework session that was already started (e.g. by magic-link
     * redemption) into the browser cookie.
     */
    public function adopt(Request $request, Session $session): void
    {
        session()->put(self::SESSION_KEY, $session->id);

        $organizationId = $session->organization_id
            ?? $this->memberships->forUser($session->user_id)->value('organization_id');

        if (is_string($organizationId)) {
            session()->put(self::ORG_KEY, $organizationId);
        }

        session()->regenerate();

        $this->applyPendingLink($session->user_id);
    }

    /**
     * Hold a verified social identity aside while the user proves control of the
     * existing account by signing in. Linking then completes automatically.
     */
    public function startPendingLink(FederatedPrincipal $principal): void
    {
        session()->put(self::PENDING_LINK_KEY, [
            'provider' => $principal->provider,
            'subject' => $principal->subject,
            'email' => $principal->email,
            'name' => $principal->name,
            'raw' => $principal->raw,
        ]);
    }

    /**
     * The human label of a provider awaiting linking (e.g. "Google"), or null.
     */
    public function pendingLinkLabel(): ?string
    {
        $pending = session()->get(self::PENDING_LINK_KEY);
        $provider = is_array($pending) && is_string($pending['provider'] ?? null) ? $pending['provider'] : null;

        return $provider === null ? null : SocialProviders::label(str_replace('social:', '', $provider));
    }

    private function applyPendingLink(string $subjectId): void
    {
        $pending = session()->pull(self::PENDING_LINK_KEY);

        if (! is_array($pending) || ! is_string($pending['provider'] ?? null) || ! is_string($pending['subject'] ?? null)) {
            return;
        }

        try {
            $this->subjects->link($subjectId, new FederatedPrincipal(
                provider: $pending['provider'],
                subject: $pending['subject'],
                email: is_string($pending['email'] ?? null) ? $pending['email'] : null,
                name: is_string($pending['name'] ?? null) ? $pending['name'] : null,
                raw: is_array($pending['raw'] ?? null) ? $pending['raw'] : [],
            ));
        } catch (IdentityAlreadyLinked) {
            // The identity is already attached elsewhere — nothing to do.
        }
    }

    public function switchOrganization(Request $request, string $organizationId): void
    {
        session()->put(self::ORG_KEY, $organizationId);
    }

    public function logout(Request $request): void
    {
        $sessionId = session()->get(self::SESSION_KEY);

        if (is_string($sessionId)) {
            $this->sessions->revoke($sessionId);
        }

        session()->forget([self::SESSION_KEY, self::ORG_KEY, self::MFA_PENDING_KEY, self::PENDING_LINK_KEY]);
        session()->invalidate();
        session()->regenerateToken();
    }
}
