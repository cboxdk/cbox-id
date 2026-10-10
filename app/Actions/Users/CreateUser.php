<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Mail\MagicLinkMail;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Locale\MailLocale;
use App\Platform\MailLinks;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Identity\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Create a person in this environment — through {@see Subjects}, so a host that has swapped
 * its own subject resolver stays authoritative, and the create is audited and announced as
 * every other door's is.
 *
 * THE TENANT'S OWN PASSWORD POLICY, ANSWERED AS AN ANSWER. A password, when one is sent, is
 * checked by the environment's policy at the credential primitive — length, reuse, the
 * breach corpus — and a refusal is a 422 with the reason (`password_policy`), never the 500
 * it once was.
 *
 * AND OPTIONALLY A WAY IN. `send_sign_in_link` mails a one-click sign-in link, minted in THIS
 * environment (a link minted in the platform root would redeem against a user pool that does
 * not contain the person). A magic link rather than an invitation, because an invitation
 * exists to make a membership, and an environment that does not use organizations has none
 * to join. The console asks a person whose own address is unconfirmed to confirm it before
 * they may hand a mailer to an address of their choosing; that hold is the console's, on the
 * person, and is asked there before this runs.
 */
#[AsAction(
    name: 'users.create',
    summary: 'Create a user in this environment, optionally with a password, and optionally mail them a one-click sign-in link.',
    scope: 'users:write',
    danger: Danger::Write,
    schema: 'User',
    tag: 'Users',
    rest: ['POST', '/users'],
    status: 201,
    consoleRoutes: ['environment.users.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CreateUser implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('email')->required()->format('email')->max(190),
            Field::string('name')->nullable()->max(190),
            Field::string('password')->nullable()->min(8)->max(255)->describe('Checked against this environment\'s password policy. Leave out for a user who signs in another way.'),
            Field::boolean('send_sign_in_link')->describe('Mail the new user a one-click sign-in link. Default false.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $email = trim($context->string('email'));

        // Asked before anything is created: a user made and then refused their link is a
        // half-done request the caller has to notice and undo.
        if ($context->boolean('send_sign_in_link') && ! app(SignInMethods::class)->magicLinkEnabled()) {
            throw ActionRefused::because('magic_link_disabled', 'Magic links are turned off for this environment, so there is no sign-in link to send.', 'send_sign_in_link');
        }

        if ($this->subjects->findByEmail($email) !== null) {
            throw ActionRefused::because('email_taken', 'A user with that email already exists in this environment.', 'email');
        }

        try {
            $subject = $this->subjects->create($email, $context->nullableString('name'), $context->nullableString('password'));
        } catch (PolicyViolation $violation) {
            throw ActionRefused::because('password_policy', $violation->getMessage(), 'password');
        }

        if ($context->boolean('send_sign_in_link')) {
            $token = app(MagicLink::class)->request($email);

            Mail::to($email)->locale(app(MailLocale::class)->forRecipient())->send(new MagicLinkMail(
                app(MailLinks::class)->route('magic.redeem', $token),
            ));
        }

        $user = User::query()->find($subject->id);

        return $user === null
            ? ActionResult::item($subject, ['id' => $subject->id, 'email' => $subject->email, 'name' => $subject->name])
            : ActionResult::item($user, UserFields::present($user));
    }
}
