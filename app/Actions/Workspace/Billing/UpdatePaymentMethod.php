<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Billing;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Billing\Client\BillingManagement;

#[AsAction(name: 'billing.payment_methods.update', summary: 'Choose a default card or remove a non-default card from this workspace.', scope: 'billing:write', danger: Danger::Write, plane: ActionPlane::Workspace, rest: ['POST', '/billing/payment-methods'], consoleRoutes: ['billing.updateCard'], consoleGate: ConsoleGate::ManageBilling, tag: 'Billing')]
final readonly class UpdatePaymentMethod implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('operation')->required()->oneOf(['default', 'remove']), Field::string('card')->required()->max(128)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        UpdateBillingDetails::available();
        $org = InWorkspace::id($context->principal);
        $billing = app(BillingManagement::class);
        $card = array_find(UpdateBillingDetails::request(fn () => $billing->paymentMethods($org)), fn ($c) => $c->id === $context->string('card'));
        if ($card === null) {
            throw ActionRefused::notFound('card');
        }
        $operation = $context->string('operation');
        if ($operation === 'remove') {
            if ($card->isDefault) {
                throw ActionRefused::because('default_card', __('billing.replace_default'));
            }
            UpdateBillingDetails::request(fn () => $billing->removePaymentMethod($org, $card->id));
        } else {
            UpdateBillingDetails::request(fn () => $billing->setDefaultPaymentMethod($org, $card->id));
        }
        InWorkspace::record($context->principal, $org, 'billing.card.'.$operation, 'payment_method', $card->id);

        return ActionResult::item(null, ['ok' => true]);
    }
}
