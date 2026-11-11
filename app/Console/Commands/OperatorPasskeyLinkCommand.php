<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Operators\OperatorPasskeyLinks;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Platform\Enums\OperatorStatus;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Console\Command;

/**
 * Break-glass for the operator plane: a one-time link on which a named operator enrols a
 * passkey ({@see OperatorPasskeyLinks}).
 *
 * For an operator who lost their only passkey, or who never had one when
 * `cbox-id.operators.phishing_resistant` was turned on. Whoever runs this already holds
 * the deployment's shell, which is the authority it rests on. Audited when issued and
 * when used.
 */
class OperatorPasskeyLinkCommand extends Command
{
    protected $signature = 'cbox-id:operator:passkey-link
        {email : The address of an active platform operator}
        {--minutes= : How long the link works (default cbox-id.operators.enrolment_link_minutes, at most 60)}';

    protected $description = 'Issue a one-time, short-lived link on which a platform operator enrols a passkey (break-glass, audited)';

    public function handle(PlatformRoot $root, OperatorPasskeyLinks $links, AuditLog $audit, Surfaces $surfaces): int
    {
        $argument = $this->argument('email');
        $email = is_string($argument) ? mb_strtolower(trim($argument)) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give the email address of an active platform operator.');

            return self::FAILURE;
        }

        if ($root->environment() === null) {
            $this->error('This deployment has no platform root environment yet.');

            return self::FAILURE;
        }

        $operator = PlatformOperator::query()->where('email', $email)->first();

        if ($operator === null || $operator->subject_id === null || $operator->subject_id === '') {
            $this->error("{$email} is not a platform operator. Grant it first: php artisan cbox-id:operator:grant {$email}");

            return self::FAILURE;
        }

        if ($operator->status !== OperatorStatus::Active) {
            $this->error("{$email} is a SUSPENDED platform operator. Reactivate it first: php artisan cbox-id:operator:grant {$email} --reactivate");

            return self::FAILURE;
        }

        $option = $this->option('minutes');
        $configured = config('cbox-id.operators.enrolment_link_minutes', 15);
        $minutes = is_numeric($option) ? (int) $option : (is_numeric($configured) ? (int) $configured : 15);
        $minutes = max(1, min(OperatorPasskeyLinks::MAX_MINUTES, $minutes));

        $issued = $links->issue($operator, $minutes);

        $root->run(fn () => $audit->record(new AuditEvent(
            action: 'operator.passkey_link.issued',
            actorType: ActorType::System,
            actorId: 'cbox-id:operator:passkey-link',
            targetType: 'operator',
            targetId: (string) $operator->id,
            context: ['subject_id' => (string) $operator->subject_id, 'minutes' => $minutes],
        )));

        $this->info("One-time passkey enrolment link for {$email}. It works once, for {$minutes} minutes:");
        // On the sign-in host: the login host in login-host mode, the app's own otherwise.
        $this->line($surfaces->loginUrl('/login/direct/passkey/'.$issued['token']));
        $this->warn('Send it over a channel you trust: whoever opens it adds a passkey to this operator. Afterwards the operator signs in at /login/direct with that passkey.');

        return self::SUCCESS;
    }
}
