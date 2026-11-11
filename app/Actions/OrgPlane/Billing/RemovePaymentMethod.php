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

#[AsAction(name: 'billing.payment_method.remove', summary: 'Remove an unneeded payment card from this organization.', scope: 'id:billing:write', danger: Danger::Write, plane: ActionPlane::Organization, rest: ['DELETE', '/billing/payment-methods/{id}'], status: 204, consoleRoutes: ['orgs.settings.billing.payment-method.remove'], consoleGate: ConsoleGate::ManageBilling, tag: 'Billing')]
final readonly class RemovePaymentMethod implements Action
{
    public function __construct(private PaymentAccount $payments) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()->max(255)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspace = InOrganization::id($context->principal);
        $this->payments->remove($workspace, $context->string('id'));
        InOrganization::record($context->principal, $workspace, 'billing.payment_method.remove', 'payment_method', $context->string('id'));

        return ActionResult::none();
    }
}
