<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Enums\OperatorStatus;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Make an existing person of the platform root a PLATFORM OPERATOR — from a shell on the
 * deployment, and from nowhere else.
 *
 * WHY IT EXISTS. The first-run screen and `cbox-id:install` make the person who installs a
 * deployment its first operator. A deployment can still end up with an owner who is not
 * one: somebody who signed up through `/signup` before the deployment was claimed, an
 * operator row removed by hand, an install that predates operators being subjects. That
 * person owns the workspace and is shut out of platform admin and of the platform root's
 * own environment console (`/admin` on the root host), which only operators administer —
 * and adding an operator from the console takes an operator. This is the way in that does
 * not: shell access to the deployment is already the deployment's highest authority.
 *
 * IDEMPOTENT. An active operator is reported and left alone. A SUSPENDED one is refused
 * unless `--reactivate` says so, because suspending an operator was somebody's decision.
 *
 * NEVER CREATES A PERSON. The address must already be a person of the platform root (they
 * signed up, were invited, or installed it). Minting a new identity with a password from a
 * command line would be a second, weaker sign-up; this only grants authority to an identity
 * that already proved who it is. The operator record links to that subject and the subject
 * keeps its own password, passkeys and second factor — the record's own password column is
 * a random value nothing ever reads.
 *
 * AUDITED, in the platform root's trail, as the system acting (`operator.granted`), naming
 * the subject and the operator record.
 */
class GrantOperatorCommand extends Command
{
    protected $signature = 'cbox-id:operator:grant
        {email : The address of an existing person of the platform root}
        {--reactivate : Reactivate the person\'s operator record if it was suspended}';

    protected $description = 'Make an existing person of the platform root a platform operator (idempotent, audited)';

    public function handle(PlatformRoot $root, Subjects $subjects, PlatformOperators $operators, AuditLog $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give the email address of an existing person of the platform root.');

            return self::FAILURE;
        }

        if ($root->environment() === null) {
            $this->error('This deployment has no platform root environment yet. Install it first (php artisan cbox-id:install, or the first-run screen).');

            return self::FAILURE;
        }

        $subject = $root->run(fn () => $subjects->findByEmail($email));

        if ($subject === null) {
            $this->error("Nobody signs in to the platform root as {$email}. Sign up or be invited first, then run this again.");

            return self::FAILURE;
        }

        $existing = PlatformOperator::query()
            ->where('subject_id', $subject->id)
            ->orWhere('email', $email)
            ->first();

        if ($existing !== null) {
            return $this->existing($existing, $subject->id, $operators, $audit);
        }

        // The framework's own provisioning, which links the record to the EXISTING subject
        // by address rather than creating one. The password it is given is the record's
        // bootstrap column, not a credential: the subject keeps its own.
        $operator = $operators->create($email, Str::password(64), $subject->name);

        if ((string) $operator->subject_id !== $subject->id) {
            $operator->forceFill(['subject_id' => $subject->id])->save();
        }

        $this->record($audit, 'operator.granted', (string) $operator->id, $subject->id);
        $this->info("{$email} is now a platform operator. They reach platform admin and the platform environment's console (/admin on the root host) at their next page load.");

        return self::SUCCESS;
    }

    private function existing(PlatformOperator $operator, string $subjectId, PlatformOperators $operators, AuditLog $audit): int
    {
        if ($operator->subject_id === null || $operator->subject_id === '') {
            $operator->forceFill(['subject_id' => $subjectId])->save();
        }

        if ((string) $operator->subject_id !== $subjectId) {
            $this->error('An operator record with this address is linked to a different person. Resolve that by hand before granting.');

            return self::FAILURE;
        }

        if ($operator->status === OperatorStatus::Active) {
            $this->info("{$operator->email} is already a platform operator. Nothing changed.");

            return self::SUCCESS;
        }

        if (! $this->option('reactivate')) {
            $this->error("{$operator->email} is a SUSPENDED platform operator. Somebody decided that; pass --reactivate to undo it.");

            return self::FAILURE;
        }

        $operators->reactivate((string) $operator->id, 'system');
        $this->record($audit, 'operator.granted', (string) $operator->id, $subjectId, ['reactivated' => true]);
        $this->info("{$operator->email} is a platform operator again.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(AuditLog $audit, string $action, string $operatorId, string $subjectId, array $context = []): void
    {
        app(PlatformRoot::class)->run(fn () => $audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::System,
            actorId: 'cbox-id:operator:grant',
            targetType: 'operator',
            targetId: $operatorId,
            context: [...$context, 'subject_id' => $subjectId],
        )));
    }
}
