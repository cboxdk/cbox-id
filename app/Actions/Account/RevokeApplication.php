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
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;

/**
 * Withdraw one application's access to your account: its refresh tokens are revoked, so it
 * can no longer act as you without asking again.
 *
 * Not "sign me out of everything" — that is the right answer to "my account is
 * compromised" and the wrong one to "I do not use that CLI any more". Scoped to you by the
 * call itself: the client id is all it takes from the caller, and it can only ever
 * withdraw your own grants. Withdrawing one that is already gone changes nothing.
 */
#[AsAction(
    name: 'account.applications.revoke',
    summary: 'Withdraw an application\'s access to your account; it must ask you again to act as you.',
    scope: 'account:applications:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/applications/{client_id}'],
    status: 204,
    consoleRoutes: ['account.applications.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Applications',
)]
final readonly class RevokeApplication implements Action
{
    public function __construct(private RefreshTokens $tokens) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('client_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $this->tokens->revokeForUserAndClient(AsPerson::subjectId($context->principal), $context->string('client_id'));

        return ActionResult::none();
    }
}
