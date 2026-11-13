<?php

declare(strict_types=1);

namespace App\Platform\Lifecycle;

use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SubjectGrantRevoker;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\PasswordResetToken;

/**
 * A password reset ends the apps the old password reached, not only the sessions.
 *
 * A reset is what somebody does when they have lost the password, or think somebody else
 * has it. The framework's reset ends every session so a thief cannot ride one past the
 * change; the refresh tokens and access tokens those sessions handed to apps went on
 * working, so whoever had used the stolen password to authorize an app of their own kept
 * it. After a reset completes, every grant of the person is withdrawn the way an
 * administrator's "reset and sign out of apps" withdraws it ({@see SubjectGrantRevoker}):
 * open codes consumed, refresh tokens withdrawn with back-channel logout, live access
 * tokens revoked.
 *
 * The person is read from the token BEFORE the reset consumes it. A refused reset (an
 * invalid link, a password the policy refuses) throws out of the inner call and withdraws
 * nothing.
 */
final readonly class WithdrawingPasswordReset implements PasswordReset
{
    public function __construct(private PasswordReset $inner, private Subjects $subjects, private SubjectGrantRevoker $grants) {}

    public function request(string $email): ?string
    {
        return $this->inner->request($email);
    }

    public function reset(string $token, string $newPassword): void
    {
        $email = PasswordResetToken::query()->where('token_hash', hash('sha256', $token))->value('email');
        $subject = is_string($email) ? $this->subjects->findByEmail($email) : null;

        $this->inner->reset($token, $newPassword);

        if ($subject !== null) {
            $this->grants->revokeGrantsForUser($subject->id);
        }
    }
}
