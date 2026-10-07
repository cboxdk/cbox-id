<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\Directory\Models\DirectoryGroup;

/**
 * Map a directory group onto a role, or unmap it: everyone the customer's identity
 * provider puts in the group holds the role as membership syncs, and loses it when they
 * leave.
 *
 * ONE ACTION FOR BOTH DIRECTIONS (`mapped`), because they are one control — a checkbox —
 * and two endpoints is how the environment console once ended up scoping only one half.
 * The group is resolved WITHIN the directory, so an id from another directory is a 404;
 * the role must be one the organization's own people may be given — the framework refuses
 * a staff-only role or another organization's, because who is in the group is decided by
 * somebody else's directory.
 */
#[AsAction(
    name: 'directories.groups.map',
    summary: 'Map a directory group onto a role (everyone in the group holds it as membership syncs), or unmap it.',
    scope: 'directory_sync:write',
    danger: Danger::Write,
    schema: 'DirectoryGroup',
    tag: 'Directory sync',
    rest: ['POST', '/directories/{id}/group-roles'],
    consoleRoutes: ['directories.map', 'environment.directories.map'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class MapDirectoryGroup implements Action
{
    public function __construct(
        private GroupRoleMappings $mappings,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            Field::string('group_id')->required()->max(64)->describe('One of the directory\'s groups.'),
            Field::string('role_id')->required()->max(64)->describe('A role the organization\'s own people may hold.'),
            Field::boolean('mapped')->required()->describe('true to map the group onto the role, false to unmap it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);

        $group = DirectoryGroup::query()
            ->whereKey($context->string('group_id'))
            ->where('directory_id', $directory->id)
            ->first() ?? throw ActionRefused::notFound('group');

        $roleId = $context->string('role_id');
        $mapped = $context->boolean('mapped');

        if ($mapped) {
            try {
                $this->mappings->map($directory->organization_id, $group->id, $roleId);
            } catch (UnknownRole) {
                throw ActionRefused::because('role_not_mappable', 'That role cannot be given to a directory group here.', 'role_id');
            }
        } else {
            $this->mappings->unmap($directory->organization_id, $group->id, $roleId);
        }

        $this->audit->record($mapped ? EnterpriseAudit::DIRECTORY_GROUP_MAPPED : EnterpriseAudit::DIRECTORY_GROUP_UNMAPPED, $context->actor(), $directory->organization_id, 'directory_group', $group->id, [
            'directory_id' => $directory->id,
            'group' => $group->display_name,
            'role_id' => $roleId,
        ]);

        return ActionResult::item($group, [
            'id' => $group->id,
            'directory_id' => $directory->id,
            'name' => $group->display_name,
            'external_id' => $group->external_id,
            'role_ids' => array_values(GroupRoleMapping::query()
                ->where('organization_id', $directory->organization_id)
                ->where('group_id', $group->id)
                ->pluck('role_id')
                ->all()),
        ]);
    }
}
