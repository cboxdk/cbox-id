<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Database\Eloquent\Builder;

/**
 * The downstream targets this environment pushes people to — or one organization's —
 * with whether each is failing: a push that has started failing means people are
 * drifting out of step somewhere downstream.
 */
#[AsAction(
    name: 'provisioning.targets.list',
    summary: 'List the downstream SCIM targets people are provisioned to, optionally for one organization, with their failures. Never their credentials.',
    scope: 'provisioning:read',
    danger: Danger::Read,
    schema: 'ProvisioningTarget',
    tag: 'Outbound provisioning',
    rest: ['GET', '/provisioning-targets'],
)]
final class ListProvisioningTargets implements Action
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
            ProvisioningConnection::query()->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            $context,
            ProvisioningFields::present(...),
        );
    }
}
