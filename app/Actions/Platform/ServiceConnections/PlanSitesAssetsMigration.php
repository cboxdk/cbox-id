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
use App\ServiceConnections\SitesAssetsMigration;

#[AsAction(
    name: 'admin.service_connections.sites_assets.plan',
    summary: 'Preview the fixed Sites-to-Assets grants for the deployment-attested migration inventory without creating connections.',
    scope: 'operator:service-connections:read', danger: Danger::Read, plane: ActionPlane::Platform,
    rest: ['GET', '/sites-assets-migrations/plan'], consoleGate: ConsoleGate::Operator,
)]
final readonly class PlanSitesAssetsMigration implements Action
{
    public function __construct(private SitesAssetsMigration $migration) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);
        $plan = $this->migration->plan();

        return ActionResult::item($plan, ['plan' => $plan]);
    }
}
