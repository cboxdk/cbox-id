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
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\Directory\Models\DirectoryGroup;

/**
 * The groups a directory has synced, with the roles each is mapped onto — the ids
 * {@see MapDirectoryGroup} takes. Who is IN a group is the customer's identity provider's
 * business, and not listed here.
 */
#[AsAction(
    name: 'directories.groups.list',
    summary: 'List the groups a directory has synced, with the role ids each group is mapped onto.',
    scope: 'directory_sync:read',
    danger: Danger::Read,
    schema: 'DirectoryGroup',
    tag: 'Directory Sync',
    rest: ['GET', '/directories/{id}/groups'],
)]
final class ListDirectoryGroups implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::directory($context);

        $result = $this->page(DirectoryGroup::query()->where('directory_id', $directory->id), $context, static fn (DirectoryGroup $group): array => [
            'id' => $group->id,
            'directory_id' => $group->directory_id,
            'name' => $group->display_name,
            'external_id' => $group->external_id,
            'role_ids' => [],
        ]);

        // The mappings of THIS page's groups, in one query rather than one per group.
        /** @var list<array<string, mixed>> $rows */
        $rows = $result->payload ?? [];
        $mapped = GroupRoleMapping::query()
            ->where('organization_id', $directory->organization_id)
            ->whereIn('group_id', array_column($rows, 'id'))
            ->get(['group_id', 'role_id'])
            ->groupBy('group_id');

        foreach ($rows as $index => $row) {
            $roles = $mapped->get(is_string($row['id'] ?? null) ? $row['id'] : '');
            $rows[$index]['role_ids'] = $roles === null ? [] : array_values($roles->pluck('role_id')->all());
        }

        return ActionResult::page($result->value, $rows, $result->meta ?? []);
    }
}
