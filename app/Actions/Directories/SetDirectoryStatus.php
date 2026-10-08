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
use Cbox\Id\Directory\Enums\DirectoryStatus;

/**
 * Pause a directory's provisioning, or resume it. The state is said (`active`) rather
 * than toggled, so a retry cannot undo itself; the console's one switch sends the
 * opposite of what the row shows.
 */
#[AsAction(
    name: 'directories.status.set',
    summary: 'Pause or resume an inbound directory\'s provisioning.',
    scope: 'directory_sync:write',
    danger: Danger::Write,
    schema: 'Directory',
    tag: 'Directory sync',
    rest: ['POST', '/directories/{id}/status'],
    consoleRoutes: ['directories.toggle', 'environment.directories.toggle'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetDirectoryStatus implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            Field::boolean('active')->required()->describe('true to resume provisioning, false to pause it.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);
        $active = $context->boolean('active');
        $target = $active ? DirectoryStatus::Active : DirectoryStatus::Paused;

        if ($directory->status !== $target) {
            $directory->status = $target;
            $directory->save();

            $this->audit->record($active ? EnterpriseAudit::DIRECTORY_RESUMED : EnterpriseAudit::DIRECTORY_PAUSED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
                'name' => $directory->name,
            ]);
        }

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
