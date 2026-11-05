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
use Cbox\Id\Identity\Contracts\TotpDevices;
use Cbox\Id\Identity\Exceptions\TotpDeviceRefused;

#[AsAction(
    name: 'account.totp.rename', summary: 'Rename one of your authenticator apps.',
    scope: 'account:sign_in:write', danger: Danger::Write, plane: ActionPlane::Account,
    rest: ['PATCH', '/two-factor/apps/{factor_id}'], status: 204,
    consoleRoutes: ['account.mfa.totp.update'], consoleGate: ConsoleGate::Person, tag: 'Sign-in methods',
)]
final readonly class RenameTotpDevice implements Action
{
    public function __construct(private TotpDevices $devices) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('factor_id')->inPath(),
            Field::string('name')->required()->min(1)->max(120),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $renamed = $this->devices->renameDevice(AsPerson::subjectId($context->principal), $context->string('factor_id'), $context->string('name'));
        } catch (TotpDeviceRefused $refused) {
            throw ActionRefused::because($refused->reason, 'Use a name of 1 to 120 characters without line breaks.', 'name');
        }
        if (! $renamed) {
            throw ActionRefused::notFound('authenticator');
        }

        return ActionResult::none();
    }
}
