<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * Delete a directory: its token stops working and syncing stops. The people it
 * provisioned keep their accounts.
 */
#[AsAction(
    name: 'directories.delete',
    summary: 'Delete an inbound directory. Its token stops working; the people it provisioned keep their accounts.',
    scope: 'directory_sync:write',
    danger: Danger::Destructive,
    tag: 'Directory sync',
    rest: ['DELETE', '/directories/{id}'],
    status: 204,
    consoleRoutes: ['directories.destroy', 'environment.directories.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteDirectory implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);

        $directory->delete();

        $this->audit->record(EnterpriseAudit::DIRECTORY_DELETED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $directory->provider->value,
        ]);

        return ActionResult::none($directory);
    }
}
