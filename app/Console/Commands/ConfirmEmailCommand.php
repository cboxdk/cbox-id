<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Console\Command;

/**
 * Confirm a person's address from the command line, when mail cannot do it.
 *
 * The console refuses changes from an unconfirmed address and offers a button that mails a
 * link. When the deployment cannot send mail at all — the case that made this exist: the
 * mail server's DNS name disappeared, so the owner of cboxid.com could neither receive a
 * link nor make any change — the only party left who can vouch for the address is whoever
 * runs commands in the deployment. Audited as `user.email_confirmed_by_operator`, with the
 * address that was confirmed.
 */
class ConfirmEmailCommand extends Command
{
    protected $signature = 'cbox-id:users:confirm-email
        {email : The address of an existing person of the platform root}';

    protected $description = 'Mark a platform-root person\'s email address as confirmed (audited) — for when mail cannot deliver the link';

    public function handle(PlatformRoot $root, Subjects $subjects, AuditLog $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give the email address of an existing person of the platform root.');

            return self::FAILURE;
        }

        if ($root->environment() === null) {
            $this->error('This deployment has no platform root environment yet.');

            return self::FAILURE;
        }

        $subject = $root->run(fn () => $subjects->findByEmail($email));

        if ($subject === null) {
            $this->error("Nobody signs in to the platform root as {$email}.");

            return self::FAILURE;
        }

        if ($subject->emailVerified) {
            $this->info("{$email} is already confirmed. Nothing changed.");

            return self::SUCCESS;
        }

        $root->run(function () use ($subjects, $subject, $email, $audit): void {
            $subjects->markEmailVerified($subject->id, $email);
            $audit->record(new AuditEvent(
                action: 'user.email_confirmed_by_operator',
                actorType: ActorType::System,
                actorId: 'cbox-id:users:confirm-email',
                targetType: 'user',
                targetId: $subject->id,
                context: ['email' => $email],
            ));
        });

        $this->info("{$email} is confirmed. The console's changes are open to them at their next page load.");

        return self::SUCCESS;
    }
}
