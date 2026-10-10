<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Pipes\Contracts\PipeConnections;
use Cbox\Id\Pipes\Models\PipeConnection;

/**
 * Disconnect one of your connected services (a GitHub or Google account an app here may
 * act through). Revoked at the provider where it supports that, and forgotten here. Not a
 * sign-in method — those are {@see UnlinkSocialAccount} — so there is no last-factor
 * guard: nobody signs in through a pipe.
 *
 * Only ever YOUR connection: the lookup is by your own subject id, so somebody else's
 * answers exactly like one you never made.
 */
#[AsAction(
    name: 'account.pipes.disconnect',
    summary: 'Disconnect one of your connected services, revoking the access you gave it.',
    scope: 'account:pipes:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/pipes/{provider}'],
    status: 204,
    consoleRoutes: ['account.pipes.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Connected services',
)]
final readonly class DisconnectConnectedService implements Action
{
    public function __construct(private PipeConnections $connections) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('provider')->inPath()->max(64)->describe('The provider\'s key, for example `github`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);

        $connection = PipeConnection::query()
            ->where('user_id', $subjectId)
            ->where('provider', $context->string('provider'))
            ->first()
            ?? throw ActionRefused::notFound('connected service');

        $this->connections->disconnect($connection->id, $subjectId, $context->actor());

        return ActionResult::none();
    }
}
