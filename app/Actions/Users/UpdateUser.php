<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;

/**
 * Change a person's display name and/or address — through {@see Subjects::update()}, so the
 * change is audited as `user.updated` and announced (which is what carries it to a webhook
 * subscriber and the outbound SCIM push). A changed address loses its verification; the
 * contract clears it.
 *
 * CRITICAL, because the address is the account's RECOVERY channel: pointing it somewhere
 * else and then asking for a password reset is a takeover in two calls, and the second one
 * needs nothing more than this scope. A key whose policy holds critical actions for a
 * person's approval holds this one too.
 */
#[AsAction(
    name: 'users.update',
    summary: 'Change a user\'s name and/or email address. A changed address must be verified again.',
    scope: 'users:write',
    danger: Danger::Critical,
    schema: 'User',
    tag: 'Users',
    rest: ['PATCH', '/users/{id}'],
    consoleRoutes: ['environment.users.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateUser implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
            Field::string('name')->nullable()->max(190),
            Field::string('email')->format('email')->max(190)->describe('The new address. Its verification is cleared.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $email = $context->nullableString('email');
        $email = $email === null ? null : trim($email);
        $changed = $email !== null && mb_strtolower($email) !== mb_strtolower($user->email);

        if ($changed && User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw ActionRefused::because('email_taken', 'Another user already uses that email in this environment.', 'email');
        }

        $this->subjects->update(
            $user->id,
            $context->has('name') ? trim($context->string('name')) : null,
            $changed ? $email : null,
        );

        $fresh = $user->fresh() ?? $user;

        return ActionResult::item($fresh, UserFields::present($fresh));
    }
}
