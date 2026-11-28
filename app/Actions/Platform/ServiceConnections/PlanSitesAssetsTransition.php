<?php

declare(strict_types=1);

namespace App\Actions\Platform\ServiceConnections;

use App\Actions\Platform\AsOperator;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\ServiceConnections\SitesAssetsTransition;

#[AsAction(
    name: 'admin.service_connections.sites_assets.transition_plan',
    summary: 'Preview the attested old grant, replacement scopes and copy-manifest checksum without rotating a client.',
    scope: 'operator:service-connections:read', danger: Danger::Read, plane: ActionPlane::Platform,
    rest: ['GET', '/sites-assets-transitions/plan'], consoleGate: ConsoleGate::Operator,
)]
final readonly class PlanSitesAssetsTransition implements Action
{
    public function __construct(private SitesAssetsTransition $transition) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);
        $plan = $this->transition->plan();

        return ActionResult::item($plan, ['plan' => [...$plan->toArray(), 'plan_digest' => $plan->digest()]]);
    }
}
