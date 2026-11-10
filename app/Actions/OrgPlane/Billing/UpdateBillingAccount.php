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
use Symfony\Component\Intl\Countries;

#[AsAction(name: 'billing.account.update', summary: 'Update the organization billing contact and invoice address.', scope: 'id:billing:write', danger: Danger::Write, plane: ActionPlane::Organization, rest: ['PATCH', '/billing/account'], status: 204, consoleRoutes: ['billing.account'], consoleGate: ConsoleGate::ManageBilling, tag: 'Billing')]
final readonly class UpdateBillingAccount implements Action
{
    public function __construct(private PaymentAccount $payments) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('billing_email')->required()->format('email')->max(254),
            Field::string('invoice_name')->required()->max(255),
            Field::string('address_line1')->required()->max(255),
            Field::string('address_line2')->nullable()->max(255),
            Field::string('postal_code')->required()->max(32),
            Field::string('city')->required()->max(255),
            Field::string('country')->required()->oneOf(array_values(Countries::getCountryCodes())),
            Field::string('tax_id')->nullable()->max(64),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspace = InOrganization::id($context->principal);
        $attributes = [];
        foreach (['billing_email', 'invoice_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'tax_id'] as $field) {
            if ($context->has($field)) {
                $attributes[$field] = $context->nullableString($field);
            }
        }
        $this->payments->update($workspace, $attributes);
        InOrganization::record($context->principal, $workspace, 'billing.account.updated', 'organization', $workspace);

        return ActionResult::none();
    }
}
