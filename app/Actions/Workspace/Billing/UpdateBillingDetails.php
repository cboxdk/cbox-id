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
use Cbox\Billing\Client\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\Intl\Countries;

#[AsAction(name: 'billing.details.update', summary: 'Update the workspace billing address and invoice details.', scope: 'billing:write', danger: Danger::Write, plane: ActionPlane::Workspace, rest: ['PUT', '/billing/details'], consoleRoutes: ['billing.details'], consoleGate: ConsoleGate::ManageBilling, tag: 'Billing')]
final readonly class UpdateBillingDetails implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('invoice_name')->required()->max(255),
            Field::string('billing_email')->required()->max(254),
            Field::string('address_line_1')->required()->max(255),
            Field::string('address_line_2')->nullable()->max(255),
            Field::string('postal_code')->required()->max(32),
            Field::string('city')->required()->max(128),
            Field::string('country')->required()->oneOf(array_keys(Countries::getNames())),
            Field::string('subdivision')->nullable()->max(64),
            Field::string('tax_id')->nullable()->max(64),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        self::available();
        $org = InWorkspace::id($context->principal);
        $input = [];
        foreach (['invoice_name', 'billing_email', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country', 'subdivision', 'tax_id'] as $field) {
            $input[$field] = trim($context->string($field));
        }
        if (filter_var($input['billing_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ActionRefused::because('validation_failed', 'Use a valid billing email.', 'billing_email');
        }
        if (in_array($input['country'], ['US', 'CA'], true) && $input['subdivision'] === '') {
            throw ActionRefused::because('validation_failed', 'State or province is required.', 'subdivision');
        }
        $input['tax_id'] = strtoupper(str_replace([' ', '.', '-'], '', $input['tax_id']));
        if ($input['country'] === 'DK' && preg_match('/^\d{8}$/', $input['tax_id'])) {
            $input['tax_id'] = 'DK'.$input['tax_id'];
        }
        $profile = self::request(fn () => app(BillingManagement::class)->upsertOrganization($org, $input + ['name' => $input['invoice_name'], 'customer_account' => true]));
        InWorkspace::record($context->principal, $org, 'billing.details.updated', 'organization', $org);

        return ActionResult::item($profile, [
            'id' => $profile->id, 'name' => $profile->name, 'billingEmail' => $profile->billingEmail,
            'addressLine1' => $profile->addressLine1, 'addressLine2' => $profile->addressLine2,
            'postalCode' => $profile->postalCode, 'city' => $profile->city, 'subdivision' => $profile->subdivision,
            'country' => $profile->country, 'taxId' => $profile->taxId,
            'taxIdValidated' => $profile->taxIdValidated, 'taxIdSupported' => $profile->taxIdSupported,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    public static function request(callable $request): mixed
    {
        try {
            return $request();
        } catch (TransportException|ConnectionException $e) {
            report($e);
            throw new ActionRefused('billing_unavailable', __('billing.unavailable'), 503);
        }
    }

    public static function available(): void
    {
        if (config('billing.enabled') !== true) {
            throw ActionRefused::notFound('billing');
        }
        if (! config('billing-client.base_url') || ! config('billing-client.api_token')) {
            throw new ActionRefused('billing_unavailable', __('billing.unavailable'), 503);
        }
    }
}
