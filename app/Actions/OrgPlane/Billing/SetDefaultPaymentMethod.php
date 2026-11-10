<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Billing;

use App\Actions\OrgPlane\InOrganization;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Billing\PaymentAccount;

#[AsAction(name: 'billing.payment_method.default', summary: 'Choose the organization default payment card.', scope: 'id:billing:write', danger: Danger::Write, plane: ActionPlane::Organization, rest: ['PATCH', '/billing/payment-methods/{id}/default'], status: 204, consoleRoutes: ['billing.payment-method.default'], consoleGate: ConsoleGate::ManageBilling, tag: 'Billing')]
final readonly class SetDefaultPaymentMethod implements Action
{
    public function __construct(private PaymentAccount $payments) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()->max(255)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspace = InOrganization::id($context->principal);
        $this->payments->setDefault($workspace, $context->string('id'));
        InOrganization::record($context->principal, $workspace, 'billing.payment_method.default', 'payment_method', $context->string('id'));

        return ActionResult::none();
    }
}
