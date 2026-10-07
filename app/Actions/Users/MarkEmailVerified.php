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
use Cbox\Id\Identity\Contracts\Subjects;

/**
 * Mark a person's address verified without them following a link.
 *
 * CRITICAL: a verified address is what lets the account be recovered by mail, so on an
 * address somebody else controls this is a takeover with one more step rather than a
 * lesser act. The console asks for a fresh password first.
 */
#[AsAction(
    name: 'users.verify',
    summary: 'Mark a user\'s email address verified without the emailed link. It can then be used to recover the account.',
    scope: 'users:write',
    danger: Danger::Critical,
    schema: 'User',
    tag: 'Users',
    rest: ['POST', '/users/{id}/verify'],
    consoleRoutes: ['environment.users.verify'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class MarkEmailVerified implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $this->subjects->markEmailVerified($user->id, $user->email);

        $fresh = $user->fresh() ?? $user;

        return ActionResult::item($fresh, UserFields::present($fresh));
    }
}
