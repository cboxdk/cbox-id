<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\FrontendApi\LoginTicket;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\EmailVerificationToken;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\LoginAttemptCounter;
use Cbox\Id\Identity\Models\MagicLinkToken;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\MfaRecoveryCode;
use Cbox\Id\Identity\Models\PasswordAge;
use Cbox\Id\Identity\Models\PasswordChangeRequirement;
use Cbox\Id\Identity\Models\PasswordHistoryEntry;
use Cbox\Id\Identity\Models\PasswordResetToken;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\UserApiToken;
use Illuminate\Support\Facades\DB;

/**
 * THE MAILBOX OWNER TAKES OVER AN ACCOUNT NOBODY HAD PROVEN.
 *
 * Self-service signup makes a usable account for whatever address is typed, and the
 * address stays unconfirmed until somebody opens the link. Until then the account belongs
 * to whoever chose its password — which need not be the person who reads that inbox.
 *
 * Some doors are opened by a link only the mailbox receives: an emailed invitation is
 * one. Whoever walks through such a door has PROVEN the address, and an account matched
 * by that address must be handed to them, not to whoever registered it first. So,
 * before anything is attached to the account, everything set on it before the proof is
 * withdrawn — password, second factors, passkeys, federated links, personal keys, every
 * session and refresh token — and the address is marked confirmed, all in one step.
 *
 * The rule is "an unconfirmed account never receives a membership": a door that cannot
 * prove the mailbox (an administrator typing an address) refuses instead of calling this.
 *
 * Nothing happens to an account whose address was already confirmed: that one has an
 * owner, and the proof just given is theirs.
 */
final readonly class UnverifiedAccountClaim
{
    public function __construct(
        private Subjects $subjects,
        private SessionManager $sessions,
        private RefreshTokens $grants,
        private TenantContext $tenants,
        private AuditLog $audit,
    ) {}

    /**
     * Hand the account matched by `$provenEmail` to whoever proved that mailbox.
     *
     * @return Subject the account as it now stands (confirmed)
     */
    public function claim(Subject $subject, string $provenEmail): Subject
    {
        if ($subject->emailVerified) {
            return $subject;
        }

        DB::transaction(function () use ($subject, $provenEmail): void {
            $id = $subject->id;

            // Through the query builder: the model's `hashed` cast has nothing to say
            // about a null, and no hook should be asked to approve removing a credential.
            User::query()->whereKey($id)->update(['password' => null]);

            foreach ([
                WebAuthnCredential::query()->where('user_id', $id),
                MfaFactor::query()->where('user_id', $id),
                MfaRecoveryCode::query()->where('user_id', $id),
                PasswordHistoryEntry::query()->where('user_id', $id),
                PasswordAge::query()->where('user_id', $id),
                PasswordChangeRequirement::query()->where('user_id', $id),
                EmailVerificationToken::query()->where('user_id', $id),
                LoginAttemptCounter::query()->where('user_id', $id),
                IdentityLink::query()->where('user_id', $id),
                LoginTicket::query()->where('subject_id', $id),
            ] as $query) {
                $query->toBase()->delete();
            }

            // Keyed by address, not by subject: a reset or sign-in link requested before
            // the proof went to the same inbox, but it was asked for on the old account.
            $address = mb_strtolower((string) $subject->email);
            PasswordResetToken::query()->whereRaw('lower(email) = ?', [$address])->toBase()->delete();
            MagicLinkToken::query()->whereRaw('lower(email) = ?', [$address])->toBase()->delete();

            $this->tenants->withoutScope(static function () use ($id): void {
                UserApiToken::query()->where('user_id', $id)->toBase()->delete();
                CustomerApiKey::query()->where('user_id', $id)->toBase()->delete();
            });

            $this->grants->withdrawAccess($id);
            $this->sessions->revokeAllForUser($id);

            $this->subjects->markEmailVerified($id, (string) $subject->email);

            $this->audit->record(new AuditEvent(
                action: 'user.unverified_account_claimed',
                actorType: ActorType::System,
                targetType: 'user',
                targetId: $id,
                context: ['email' => $provenEmail],
            ));
        });

        return $this->subjects->find($subject->id) ?? $subject;
    }
}
