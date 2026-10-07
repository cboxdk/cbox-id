<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Mail\AdminAssignedPasswordMail;
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
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Illuminate\Support\Facades\Mail;

/**
 * Replace a person's password on the administrator's authority — {@see AdminPasswords},
 * which checks the environment's password policy, applies the change-required flag, the
 * expiry and how much existing access to cut off, and records
 * `user.password_set_by_admin` with the reason given.
 *
 * CRITICAL: it replaces somebody else's credential. The console asks the person for a fresh
 * password first; a key whose policy holds critical actions waits for its owner's approval.
 *
 * The new password is the caller's to know already — it is never in the answer. With
 * `send_email` (the default) it is mailed to the person; without, the caller hands it over
 * themselves, which is what the console's "reveal" does from its own request.
 */
#[AsAction(
    name: 'users.password.set',
    summary: 'Set a user\'s password, temporary by default, and mail it to them unless told not to. Signs them out according to `revoke`.',
    scope: 'users:write',
    danger: Danger::Critical,
    schema: 'User',
    tag: 'Users',
    rest: ['POST', '/users/{id}/password'],
    consoleRoutes: ['environment.users.password'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SetUserPassword implements Action
{
    public function __construct(private AdminPasswords $passwords) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
            Field::string('password')->required()->max(200)->describe('The new password. Checked against this environment\'s password policy.'),
            Field::string('reason')->required()->max(200)->describe('Why — recorded on the audit trail.'),
            Field::boolean('temporary')->describe('The user must choose a new password at their next sign-in. Default true.'),
            Field::integer('expires_in_hours')->min(0)->max(8760)->describe('A temporary password stops working after this many hours. 0 or absent: until changed.'),
            Field::string('revoke')->oneOf(array_map(static fn (PasswordRevocationScope $scope): string => $scope->value, PasswordRevocationScope::cases()))->describe('How much existing access to end. Default `sessions_and_tokens`.'),
            Field::boolean('send_email')->describe('Mail the password to the user. Default true.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $password = $context->string('password');
        $temporary = $context->boolean('temporary', true);
        $hours = $context->input['expires_in_hours'] ?? 0;
        $hours = is_numeric($hours) ? (int) $hours : 0;
        $expiresAt = $temporary && $hours > 0 ? now()->addHours($hours) : null;
        $actor = $context->actor();

        try {
            $this->passwords->assign(new AdminPasswordAssignment(
                userId: $user->id,
                password: $password,
                temporary: $temporary,
                expiresAt: $expiresAt,
                revoke: PasswordRevocationScope::tryFrom($context->string('revoke')) ?? PasswordRevocationScope::SessionsAndTokens,
                actorType: $actor->type->value,
                actorId: $actor->id,
                reason: trim($context->string('reason')),
            ));
        } catch (PolicyViolation $violation) {
            throw ActionRefused::because('password_policy', $violation->getMessage(), 'password');
        }

        if ($context->boolean('send_email', true)) {
            Mail::to($user->email)->locale(app(MailLocale::class)->forRecipient())->send(new AdminAssignedPasswordMail(
                password: $password,
                temporary: $temporary,
                expiresAt: $expiresAt,
            ));
        }

        $fresh = $user->fresh() ?? $user;

        return ActionResult::item($fresh, UserFields::present($fresh));
    }
}
