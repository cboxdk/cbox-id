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
 * Rename a directory. The name is all there is to change about one after it is
 * connected: a SCIM directory is re-keyed by rotation, and a pull directory is reconnected
 * with new credentials.
 */
#[AsAction(
    name: 'directories.update',
    summary: 'Rename an inbound directory.',
    scope: 'directory_sync:write',
    danger: Danger::Write,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['PATCH', '/directories/{id}'],
    consoleRoutes: ['directories.update', 'environment.directories.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateDirectory implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            Field::string('name')->required()->max(120)->describe('The new name.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);
        $from = $directory->name;

        $directory->name = trim($context->string('name'));
        $directory->save();

        $this->audit->record(EnterpriseAudit::DIRECTORY_RENAMED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'from' => $from,
            'name' => $directory->name,
        ]);

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
