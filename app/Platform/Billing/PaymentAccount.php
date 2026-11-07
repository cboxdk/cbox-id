<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Actions\ActionRefused;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;

final readonly class PaymentAccount
{
    public function __construct(private BillingManagement $billing) {}

    /** @param array<string, mixed> $attributes */
    public function update(string $organization, array $attributes): void
    {
        $this->call(fn () => $this->billing->upsertOrganization($organization, [...$attributes, 'customer_account' => true]));
    }

    public function setDefault(string $organization, string $method): void
    {
        $this->call(function () use ($organization, $method): void {
            if (! collect($this->billing->paymentMethods($organization))->contains('id', $method)) {
                throw ActionRefused::notFound('payment_method');
            }
            $this->billing->setDefaultPaymentMethod($organization, $method);
        });
    }

    public function remove(string $organization, string $method): void
    {
        $this->call(function () use ($organization, $method): void {
            $methods = $this->billing->paymentMethods($organization);
            $selected = collect($methods)->firstWhere('id', $method);
            if ($selected === null) {
                throw ActionRefused::notFound('payment_method');
            }
            // Billing alone sees every product sharing this payer and locks its decision.
            $this->billing->removePaymentMethod($organization, $method);
        });
    }

    private function call(callable $change): void
    {
        try {
            $change();
        } catch (TransportException $error) {
            $body = json_decode($error->body ?? '', true);
            if ($error->status === 422 && is_array($body) && ($body['error'] ?? null) === 'payment_method_required') {
                throw ActionRefused::because('payment_method_required', __('billing.default_required'), 'payment');
            }
            report($error);
            throw new ActionRefused('billing_unavailable', __('billing.payment_failed'), 503, 'payment');
        }
    }
}
