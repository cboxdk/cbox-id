<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Directory\Models\Directory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The directories syncing people into this environment — or one organization's — with the
 * last error each sync hit, which is the one thing worth seeing from a list: people
 * drifting out of step with the customer's own directory.
 */
#[AsAction(
    name: 'directories.list',
    summary: 'List the directories (SCIM, Google Workspace, Microsoft Entra) syncing people in, optionally for one organization, with their last sync error.',
    scope: 'directory_sync:read',
    danger: Danger::Read,
    schema: 'Directory',
    tag: 'Directory sync',
    rest: ['GET', '/directories'],
)]
final class ListDirectories implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            EnterpriseReach::narrowField(list: true),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return $this->page(
            Directory::query()->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            $context,
            static fn (Directory $directory): array => DirectoryFields::present($directory),
        );
    }
}
