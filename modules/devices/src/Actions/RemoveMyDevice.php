<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Actions;

use App\Actions\Account\AsPerson;
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
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Remove one of your trusted devices — the phone that approves your sign-ins and your
 * agents' requests. Gone, it is asked nothing more.
 *
 * SCOPED TO YOU in the query, so somebody else's device behaves exactly like a missing
 * one: a 404, never a 403 — it is a row you have no business learning exists. Recorded as
 * `device.removed`, as you, the way My devices has always recorded it.
 */
#[AsAction(
    name: 'account.devices.remove',
    summary: 'Remove one of your trusted devices; it is no longer asked to approve anything.',
    scope: 'account:devices:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/devices/{device_id}'],
    status: 204,
    consoleRoutes: ['devices.mine.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Devices',
)]
final readonly class RemoveMyDevice implements Action
{
    public function __construct(private AuditLog $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('device_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);

        $device = Device::query()
            ->whereKey($context->string('device_id'))
            ->where('subject_id', $subjectId)
            ->first() ?? throw ActionRefused::notFound('device');

        $device->delete();

        $this->audit->record(new AuditEvent(
            action: 'device.removed',
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'device',
            targetId: $device->id,
            ip: request()->ip(),
        ));

        return ActionResult::none();
    }
}
