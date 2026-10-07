<?php

declare(strict_types=1);

namespace App\Actions\Permissions;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\Actions\Paginates;
use App\Platform\Console\LikeTerm;
use Cbox\Id\AccessControl\Models\Permission;
use Illuminate\Database\Eloquent\Builder;

/**
 * The permission catalogue roles are composed from, a page at a time: what apps declared
 * and what was authored here.
 *
 * Without `organization_id`, the environment's shared tier and every app's declared keys —
 * never one organization's private `feature:action` keys, which name what that customer
 * bought. With it, what that organization can see: the shared tier plus its own.
 */
#[AsAction(
    name: 'permissions.list',
    summary: 'List the permission catalogue roles are composed from, a page at a time. Narrow by `client_id` (one app\'s), `organization_id` (what it can see) or a `q` fragment.',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'Permission',
    tag: 'Roles',
    rest: ['GET', '/permissions'],
)]
final class ListPermissions implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...self::pageFields(),
            Field::string('organization_id')->max(64)->describe('Also the permissions this organization authored for itself.'),
            Field::string('client_id')->max(255)->describe('Only the permissions this app declared.'),
            Field::string('q')->max(120)->describe('Only permissions whose key or description contains this.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));
        $query = Permission::query()->visibleToOrganization($organizationId);

        $clientId = $context->nullableString('client_id');

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $term = trim($context->string('q'));

        if ($term !== '') {
            $like = LikeTerm::containing($term);

            $query->where(fn (Builder $q): Builder => $q
                ->whereRaw($like->sqlFor('name'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('description'), [$like->pattern]));
        }

        return $this->page($query, $context, PermissionFields::present(...));
    }
}
