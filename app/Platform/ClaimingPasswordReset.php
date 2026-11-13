<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\MailboxProof;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\PasswordResetToken;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * A RESET LINK PROVES THE MAILBOX, SO IT DECIDES WHO HOLDS AN UNVERIFIED ACCOUNT.
 *
 * A sign-in link and an invitation already hand an account whose address nobody confirmed
 * to whoever proves that address, withdrawing everything set on it before
 * ({@see MailboxProof}). The emailed password reset is the same proof and did not: it set
 * the new password and left the rest. An account that a federated identity created under
 * somebody else's address (an organization's own IdP may assert any address) kept that
 * identity linked, so after the real owner reset their way in, the IdP that claimed the
 * address went on signing into what was now their account. Factors, passkeys and keys set
 * by whoever held the account before stayed too.
 *
 * Now the reset claims the account first, through the same proof, and then sets the
 * password. One transaction: a password the policy refuses rolls the claim back with it,
 * so a refused reset changes nothing. An account whose address is already confirmed is
 * reset exactly as before.
 */
final readonly class ClaimingPasswordReset implements PasswordReset
{
    public function __construct(
        private PasswordReset $inner,
        private Subjects $subjects,
        private MailboxProof $proof,
        private AuditLog $audit,
    ) {}

    public function request(string $email): ?string
    {
        return $this->inner->request($email);
    }

    public function reset(string $token, string $newPassword): void
    {
        DB::transaction(function () use ($token, $newPassword): void {
            $record = PasswordResetToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            $subject = $record === null ? null : $this->subjects->findByEmail($record->email);

            // Nothing to claim: an unusable token, or a confirmed account. The framework's
            // own reset decides, and refuses what it must.
            if ($record === null || $subject === null || $subject->emailVerified || $subject->email === null
                || mb_strtolower($subject->email) !== mb_strtolower($record->email)) {
                $this->inner->reset($token, $newPassword);

                return;
            }

            // The proof spends every reset link for the address (this one included), ends
            // every session and withdraws what was set on the account before; the new
            // password is the first credential set after it.
            $claimed = $this->proof->claim($subject, $record->email);

            $this->subjects->setPassword($claimed->id, $newPassword);

            $this->audit->record(new AuditEvent(
                action: 'user.password_reset',
                actorType: ActorType::User,
                actorId: $claimed->id,
                targetType: 'user',
                targetId: $claimed->id,
            ));
        });
    }
}
