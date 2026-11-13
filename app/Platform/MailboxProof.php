<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\SubjectGrantRevoker;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\MfaRecoveryCode;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\UserApiToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A sign-in link has just proven the mailbox: whoever holds it holds the account.
 *
 * AN UNVERIFIED ACCOUNT IS NOBODY'S YET. Signup makes an account usable before its address
 * is confirmed, so the password, second factors, passkeys, linked sign-ins, sessions, app
 * grants and API keys on it were set by whoever typed the address — not necessarily the
 * person who receives its mail. When that person proves the mailbox, the address is marked
 * verified and everything attached before the proof is removed, so they end up the only one
 * who can get in. They set their own password and factors afterwards.
 *
 * A VERIFIED ACCOUNT IS LEFT ALONE: its credentials were added by someone who had already
 * proven the address, and a sign-in link is no reason to take them away (nor a second factor:
 * the challenge still follows).
 *
 * The session the link just started is the one kept.
 */
final readonly class MailboxProof
{
    public function __construct(
        private Subjects $subjects,
        private SessionManager $sessions,
        private SubjectGrantRevoker $grants,
        private Mfa $mfa,
        private TenantContext $tenants,
        private AuditLog $audit,
    ) {}

    public function claim(Session $proven): void
    {
        $subjectId = $proven->user_id;
        $subject = $this->subjects->find($subjectId);

        if ($subject === null || $subject->emailVerified || $subject->email === null) {
            return;
        }

        $removed = DB::transaction(function () use ($subjectId): array {
            $removed = [
                'password' => $this->removePassword($subjectId),
                'mfa' => MfaFactor::query()->where('user_id', $subjectId)->exists()
                    || MfaRecoveryCode::query()->where('user_id', $subjectId)->exists(),
                'passkeys' => WebAuthnCredential::query()->where('user_id', $subjectId)->delete(),
                'linked_identities' => IdentityLink::query()->where('user_id', $subjectId)->delete(),
                'api_keys' => $this->revokeApiKeys($subjectId),
            ];

            if ($removed['mfa']) {
                $this->mfa->disable($subjectId, ActorType::System);
            }

            return $removed;
        });

        // Every way back in the earlier holder had: their sessions (each relying party is
        // told) and the grants apps hold for this account, access tokens included.
        $earlier = Session::query()
            ->where('user_id', $subjectId)
            ->whereKeyNot($proven->id)
            ->whereNull('revoked_at')
            ->get(['id']);

        foreach ($earlier as $session) {
            $this->sessions->revoke($session->id);
        }

        $this->grants->revokeGrantsForUser($subjectId);

        $this->subjects->markEmailVerified($subjectId, $subject->email);

        if ($removed['password'] || $removed['mfa'] || $removed['passkeys'] > 0 || $removed['linked_identities'] > 0
            || $removed['api_keys'] > 0 || $earlier->isNotEmpty()) {
            $this->audit->record(new AuditEvent(
                action: 'user.unverified_credentials_removed',
                actorType: ActorType::System,
                targetType: 'user',
                targetId: $subjectId,
                context: [...$removed, 'sessions' => $earlier->count()],
            ));
        }
    }

    private function removePassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return false;
        }

        // Through the query builder, not the model: the `hashed` cast has nothing to say
        // about null, and no password-change hook fires for a credential being taken away.
        return $model::query()->whereKey($subjectId)->whereNotNull('password')->update(['password' => null]) > 0;
    }

    /** Keys and personal tokens in every organization the account was a member of. */
    private function revokeApiKeys(string $subjectId): int
    {
        return $this->tenants->withoutScope(static fn (): int => CustomerApiKey::query()
            ->where('user_id', $subjectId)->whereNull('revoked_at')->update(['revoked_at' => now()])
            + UserApiToken::query()
                ->where('user_id', $subjectId)->whereNull('revoked_at')->update(['revoked_at' => now()]));
    }
}
