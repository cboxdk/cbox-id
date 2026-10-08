<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Mail\PasswordResetMail;
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
use Cbox\Id\Identity\Contracts\PasswordReset;
use Illuminate\Support\Facades\Mail;

/**
 * Mail a person a password-reset link, to the address they already have. Nothing about
 * their credential changes until they follow it, so this is an ordinary write: the link
 * reaches only the inbox the account already trusts.
 */
#[AsAction(
    name: 'users.password_reset.send',
    summary: 'Mail a user a password-reset link at their own address.',
    scope: 'users:write',
    danger: Danger::Write,
    tag: 'Users',
    rest: ['POST', '/users/{id}/password-reset'],
    status: 204,
    consoleRoutes: ['environment.users.password-reset'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SendPasswordReset implements Action
{
    public function __construct(
        private PasswordReset $resets,
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

        $token = $this->resets->request($user->email);

        if (is_string($token)) {
            Mail::to($user->email)->locale(app(MailLocale::class)->forRecipient())->send(new PasswordResetMail($this->links->route('password.reset', $token)));
        }

        return ActionResult::none($user);
    }
}
