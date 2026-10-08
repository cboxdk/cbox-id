<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * One directory: its provider, whether it is syncing, and the last error. Never its
 * bearer token or provider credentials.
 */
#[AsAction(
    name: 'directories.get',
    summary: 'Read one inbound directory: its provider, status, SCIM base URL and last sync error. Never its token or credentials.',
    scope: 'directory_sync:read',
    danger: Danger::Read,
    schema: 'Directory',
    tag: 'Directory sync',
    rest: ['GET', '/directories/{id}'],
)]
final class ShowDirectory implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::directory($context);

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
