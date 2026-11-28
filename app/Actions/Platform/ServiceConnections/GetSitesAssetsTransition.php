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
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\ServiceConnections\SitesAssetsTransition;

#[AsAction(
    name: 'admin.service_connections.sites_assets.transition_receipt',
    summary: 'Recover a committed Sites transition by its plan digest, including after a lost response or expiry. Historical evidence is not current authorization.',
    scope: 'operator:service-connections:read', danger: Danger::Read, plane: ActionPlane::Platform,
    rest: ['GET', '/sites-assets-transitions/{id}'], consoleGate: ConsoleGate::Operator,
)]
final readonly class GetSitesAssetsTransition implements Action
{
    public function __construct(private SitesAssetsTransition $transition) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()->max(64)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);
        $receipt = $this->transition->receipt($context->string('id'));

        return ActionResult::item($receipt, $receipt->payload);
    }
}
