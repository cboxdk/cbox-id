<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Illuminate\Database\Eloquent\Builder;

/**
 * Define a role-conflict rule: two or more roles no one person may hold together —
 * whoever raises a payment should not also approve it. It is enforced from the moment it
 * exists: a grant that would break it is refused.
 *
 * For one organization, or — the environment's own authority only — for every
 * organization at once. The roles must be ones that organization's people can hold: its
 * own, or the environment-wide ones. The framework records `sod.policy_defined`.
 */
#[AsAction(
    name: 'sod_policies.create',
    summary: 'Define a role-conflict rule: two or more roles no one person may hold together. Enforced on every grant from now on.',
    scope: 'governance:write',
    danger: Danger::Write,
    schema: 'SodPolicy',
    tag: 'Governance',
    rest: ['POST', '/sod-policies'],
    status: 201,
    consoleRoutes: ['sod-policies.store', 'environment.sod-policies.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DefineSodPolicy implements Action
{
    public function __construct(private SegregationOfDuties $sod) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...EnterpriseReach::ownerFields('the rule'),
            Field::string('name')->required()->max(190)->describe('What the rule is for: "Payments maker/checker".'),
            Field::string('description')->nullable()->max(500)->describe('Why the roles conflict.'),
            Field::list('role_ids', Field::string('role_id')->max(64))->required()->min(2)->max(50)->describe('The roles no one person may hold together; at least two.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::owner($context, 'a rule');

        $roleIds = array_values(array_unique(array_filter($context->array('role_ids'), 'is_string')));

        if (count($roleIds) < 2) {
            throw ActionRefused::because('too_few_roles', 'Select at least two roles for a mutually-exclusive set.', 'role_ids');
        }

        // Roles an organization's people can actually hold: a rule naming a role from
        // another organization would constrain nothing here and leak that the id exists.
        $known = Role::query()
            ->whereIn('id', $roleIds)
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where(
                fn (Builder $scoped): Builder => $scoped->whereNull('organization_id')->orWhere('organization_id', $organizationId),
            ))
            ->pluck('id')
            ->all();

        if (count($known) !== count($roleIds)) {
            throw ActionRefused::because('unknown_role', 'Every role must be one this organization\'s people can hold.', 'role_ids');
        }

        $description = trim($context->string('description'));

        $policy = $this->sod->definePolicy($organizationId, trim($context->string('name')), $roleIds, $description !== '' ? $description : null);

        $policy->refresh();

        return ActionResult::item($policy, SodPolicyFields::present($policy));
    }
}
