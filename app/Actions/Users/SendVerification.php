<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Mail\EmailVerificationMail;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Locale\MailLocale;
use App\Platform\MailLinks;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Illuminate\Support\Facades\Mail;

/**
 * Mail a person the link that confirms their address. An address already verified is left
 * alone and nothing is sent — the answer is the same, so a retry is harmless.
 */
#[AsAction(
    name: 'users.verification.send',
    summary: 'Mail a user the link that verifies their email address. Does nothing for an address already verified.',
    scope: 'users:write',
    danger: Danger::Write,
    tag: 'Users',
    rest: ['POST', '/users/{id}/verification'],
    status: 204,
    consoleRoutes: ['environment.users.verification'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SendVerification implements Action
{
    public function __construct(
        private EmailVerification $verification,
        private MailLinks $links,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        if ($user->email_verified_at === null) {
            $token = $this->verification->issue($user->id, $user->email);

            Mail::to($user->email)->locale(app(MailLocale::class)->forRecipient())->send(new EmailVerificationMail($this->links->route('verification.verify', $token)));
        }

        return ActionResult::none($user);
    }
}
