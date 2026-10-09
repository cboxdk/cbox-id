<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\SmsFactors;

/**
 * Take away a person's phone number as a second factor — the lost or ported number — and
 * leave every other factor and their recovery codes in place. The narrow sibling of
 * {@see ResetMfa}, which removes everything.
 *
 * Through {@see SmsFactors::remove()}, audited as `user.mfa_sms_removed` with whoever asked
 * as the actor. CRITICAL: for somebody whose only factor it was, it leaves the account
 * protected by its password alone.
 */
#[AsAction(
    name: 'users.mfa.sms.remove',
    summary: 'Remove a user\'s phone number for text-message sign-in codes. Their other factors and recovery codes stay.',
    scope: 'users:write',
    danger: Danger::Critical,
    tag: 'Users',
    rest: ['DELETE', '/users/{id}/mfa/sms'],
    status: 204,
    consoleRoutes: ['environment.users.mfa.sms'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RemoveUserSmsFactor implements Action
{
    public function __construct(private SmsFactors $sms) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));
        $actor = $context->actor();

        // Idempotent, like the full reset: nothing on file is already the state asked for,
        // and is recorded as nothing — the framework audits only a removal that happened.
        $this->sms->remove($user->id, $actor->type, $actor->id);

        return ActionResult::none($user);
    }
}
