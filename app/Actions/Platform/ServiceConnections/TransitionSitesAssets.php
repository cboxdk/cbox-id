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
use App\Platform\Actions\Preflight;
use App\Platform\Actions\Principal\OperatorPrincipal;
use App\ServiceConnections\SitesAssetsTransition;
use Illuminate\Auth\Access\AuthorizationException;

#[AsAction(
    name: 'admin.service_connections.sites_assets.transition',
    summary: 'Rotate the exact attested Sites grant to ordinary Assets scopes once, retiring its old client and retaining a recovery receipt. Requires operator approval.',
    scope: 'operator:service-connections:write', danger: Danger::Critical, plane: ActionPlane::Platform,
    rest: ['POST', '/sites-assets-transitions'], consoleGate: ConsoleGate::Operator,
)]
final readonly class TransitionSitesAssets implements Action, Preflight
{
    public function __construct(private SitesAssetsTransition $transition) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::object('plan', [
                Field::string('plan_digest')->required()->max(64),
                Field::string('manifest_digest')->required()->max(64),
                Field::string('environment_id')->required(),
                Field::string('organization_id')->required(),
                Field::string('connection_id')->required(),
                Field::string('consumer_client_id')->required(),
                Field::string('provider_client_id')->required(),
                Field::string('old_service_client_id')->required(),
                Field::string('old_service_account_id')->required(),
                Field::string('expires_at')->required(),
                Field::string('service')->required(),
                Field::string('audience')->required(),
                Field::list('old_scopes', Field::string('scope'))->required()->max(2),
                Field::list('new_scopes', Field::string('scope'))->required()->max(4),
            ])->required(),
        ]);
    }

    public function preflight(ActionContext $context): void
    {
        if (! $context->principal instanceof OperatorPrincipal) {
            throw new AuthorizationException('This transition requires the operator approval flow.');
        }
        AsOperator::id($context->principal);
        $this->transition->assertPlan($context->array('plan'));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $this->preflight($context);
        $receipt = $this->transition->transition($context->array('plan'), AsOperator::id($context->principal));

        return ActionResult::item($receipt, $receipt->payload);
    }
}
