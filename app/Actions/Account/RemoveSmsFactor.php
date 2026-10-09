<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\SmsFactors;

/**
 * Remove your phone number as a second factor — the number you no longer have, the phone
 * you are retiring. Your authenticator app, passkeys and recovery codes stay.
 *
 * CRITICAL: it changes how you sign in. Adding a number stays a ceremony on the page (the
 * phone has to receive the code); removing one is a plain write, behind a fresh password in
 * the console. Keyed to you — there is no id to name somebody else's — and audited as
 * `user.mfa_sms_removed` with you as the actor.
 */
#[AsAction(
    name: 'account.mfa.sms.remove',
    summary: 'Remove your phone number for text-message sign-in codes.',
    scope: 'account:sign_in:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/mfa/sms'],
    status: 204,
    consoleRoutes: ['account.mfa.sms.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Sign-in methods',
)]
final readonly class RemoveSmsFactor implements Action
{
    public function __construct(private SmsFactors $sms) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);
        $actor = AsPerson::actor($context->principal);

        // Idempotent, like the full reset: nothing on file is already the state asked for,
        // and is recorded as nothing — the framework audits only a removal that happened.
        $this->sms->remove($subjectId, $actor->type, $actor->id);

        return ActionResult::none();
    }
}
