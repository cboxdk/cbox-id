<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\FrontendApi\LoginTicket;
use App\SignalsData\SourceSubjects;
use Cbox\Id\Identity\Contracts\MailboxProof;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\SubjectGrantRevoker;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\DatabaseMailboxProof;
use Cbox\Id\Identity\DatabaseSubjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * The framework's {@see MailboxProof}, fitted to this app's account store.
 *
 * Since laravel-id 2.8 the framework itself hands an unverified account to whoever proves
 * its mailbox — a sign-in link and an invitation both call {@see MailboxProof::claim()} —
 * and withdraws what was set on it before: password, factors, passkeys, linked sign-ins,
 * personal keys, grants and sessions.
 *
 * Two things it cannot do here on its own, which this class adds:
 *
 *  - THE PASSWORD AND THE ROW LOCK. The default only touches the users table when the
 *    bound {@see Subjects} is its own DatabaseSubjects. This app wraps that resolver
 *    ({@see SourceSubjects}), so the default would leave the earlier password in place and
 *    skip the lock that serializes two claims of one account. Both look through the
 *    wrapper to the store underneath.
 *  - THIS APP'S OWN WAYS IN. A pending Frontend API login ticket for the account was issued
 *    before the proof, so it is withdrawn with everything else.
 */
class PlatformMailboxProof extends DatabaseMailboxProof
{
    public function __construct(
        private readonly Subjects $accounts,
        SessionManager $sessions,
        SubjectGrantRevoker $grants,
        AuditLog $audit,
    ) {
        parent::__construct($accounts, $sessions, $grants, $audit);
    }

    public function claim(Subject $subject, string $provenEmail): Subject
    {
        return DB::transaction(function () use ($subject, $provenEmail): Subject {
            if ($this->ownsStorage()) {
                $this->userModel()::query()->whereKey($subject->id)->lockForUpdate()->first();
            }

            // The same decision the framework makes, read under the same lock, so the
            // app's own rows go exactly when the framework's do.
            $current = $this->accounts->find($subject->id);
            $claiming = $current !== null && ! $current->emailVerified && $current->email !== null
                && mb_strtolower($current->email) === mb_strtolower($provenEmail);

            $claimed = parent::claim($subject, $provenEmail);

            if ($claiming) {
                LoginTicket::query()->where('subject_id', $subject->id)->toBase()->delete();
            }

            return $claimed;
        });
    }

    protected function removePassword(string $subjectId): bool
    {
        if (! $this->ownsStorage()) {
            return false;
        }

        // Through the query builder, not the model: the `hashed` cast has nothing to say
        // about a null, and no password-change hook is asked to approve its removal.
        return $this->userModel()::query()
            ->whereKey($subjectId)
            ->whereNotNull('password')
            ->toBase()
            ->update(['password' => null]) > 0;
    }

    private function ownsStorage(): bool
    {
        $store = $this->accounts instanceof SourceSubjects ? $this->accounts->underlying() : $this->accounts;

        return $store instanceof DatabaseSubjects;
    }

    /** @return class-string<User> */
    private function userModel(): string
    {
        $configured = config('cbox-id.models.user');

        return is_string($configured) && is_a($configured, User::class, true) ? $configured : User::class;
    }
}
