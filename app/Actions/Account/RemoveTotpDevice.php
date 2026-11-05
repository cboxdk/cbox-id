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
    name: 'account.totp.remove', summary: 'Remove one of your authenticator apps.',
    scope: 'account:sign_in:write', danger: Danger::Critical, plane: ActionPlane::Account,
    rest: ['DELETE', '/two-factor/apps/{factor_id}'], status: 204,
    consoleRoutes: ['account.mfa.totp.destroy'], consoleGate: ConsoleGate::Person, tag: 'Sign-in methods',
)]
final readonly class RemoveTotpDevice implements Action
{
    public function __construct(private TotpDevices $devices) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('factor_id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $removed = $this->devices->removeDevice(AsPerson::subjectId($context->principal), $context->string('factor_id'));
        } catch (TotpDeviceRefused $refused) {
            throw ActionRefused::because($refused->reason, 'Add another authenticator before removing your last app.');
        }
        if (! $removed) {
            throw ActionRefused::notFound('authenticator');
        }

        return ActionResult::none();
    }
}
