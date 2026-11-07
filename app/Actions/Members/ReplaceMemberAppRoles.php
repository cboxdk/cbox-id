<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppMemberRoles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;

#[AsAction(
    name: 'members.apps.roles.replace',
    summary: 'Replace one app\'s member roles atomically. Other apps and the organization membership remain unchanged. An empty role list removes this app\'s access only.',
    scope: 'roles:write', danger: Danger::Write, schema: 'RoleAssignments', tag: 'Roles',
    rest: ['PUT', '/organizations/{organization_id}/members/{user_id}/apps/{client_id}/roles'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ReplaceMemberAppRoles implements Action
{
    public function __construct(private AppMemberRoles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64),
            Field::string('user_id')->inPath()->max(64),
            Field::string('client_id')->inPath()->max(255),
            Field::string('version')->required()->min(64)->max(64)->describe('SHA-256 of the JSON array of current role ids for this app, sorted lexicographically. Read member roles first.'),
            Field::list('roles', Field::string('role')->min(1)->max(190))->present()->max(50)->describe('Manifest keys to assign. An empty list removes manual access to this app.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $member = MemberFields::find($organization->id, $context->string('user_id'));
        $clientId = $context->string('client_id');
        $client = Client::query()->where('client_id', $clientId)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organization->id))->first();
        if ($client === null) {
            throw ActionRefused::notFound('app');
        }
        $keys = [];
        foreach ($context->array('roles') as $key) {
            if (! is_string($key)) {
                throw ActionRefused::because('invalid_role', new ActionMessage('console.each_role_must_be_a_manifest_key_199e3648'), 'roles');
            }
            $keys[] = $key;
        }
        $assignments = $this->roles->replace($member, $clientId, $keys, $context->string('version'), $context->actor());
        $rows = [];
        foreach ($assignments as $assignment) {
            $role = Role::query()->where('client_id', $clientId)->whereKey($assignment->role_id)->firstOrFail();
            $rows[] = RoleAssignmentResource::from($role, $member->user_id, $organization->id, $assignment->source);
        }

        return ActionResult::items($assignments, $rows);
    }
}
