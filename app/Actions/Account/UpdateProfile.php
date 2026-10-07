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
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\Subjects;

/**
 * Change the display name on your own account.
 *
 * The one account write that asks for no fresh password in the console: a name is not a
 * credential and changing it grants nothing, so re-entering a password to fix a typo
 * would be charging for nothing.
 */
#[AsAction(
    name: 'account.profile.update',
    summary: 'Change the display name on your own account.',
    scope: 'account:profile:write',
    danger: Danger::Write,
    plane: ActionPlane::Account,
    rest: ['PATCH', '/profile'],
    consoleRoutes: ['account.profile.update'],
    consoleGate: ConsoleGate::Person,
    schema: 'Profile',
    tag: 'Profile',
)]
final readonly class UpdateProfile implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->min(1)->max(120)->describe('Your display name.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $updated = $this->subjects->update(AsPerson::subjectId($context->principal), name: trim($context->string('name')));

        return ActionResult::item($updated, [
            'id' => $updated->id,
            'name' => $updated->name,
            'email' => $updated->email,
        ]);
    }
}
