<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use App\Platform\Billing\ProjectSubscriptions;
use Cbox\Id\Billing\Contracts\ProjectPayments;

/**
 * The project's subscription, asked of Cbox Billing.
 *
 * "Renews" is the question, not "exists": a subscription that is cancelled, or set to end
 * with its period, charges nobody again, so the project it belonged to may go.
 */
final readonly class BilledProjectSubscriptions implements ProjectSubscriptions
{
    /** The states in which Billing charges nothing further. */
    private const array ENDED = ['canceled', 'cancelled', 'expired', 'ended'];

    public function __construct(private ProjectPayments $payments) {}

    public function renews(string $organizationId, string $projectId): bool
    {
        // A deployment that does not bill, or has no Billing to ask, has no subscriptions.
        if (config('billing.enabled') !== true || ! $this->configured()) {
            return false;
        }

        $subscription = $this->payments->subscription($organizationId, $projectId);

        if ($subscription === null) {
            return false;
        }

        $status = $subscription['status'] ?? null;

        if (is_string($status) && in_array(strtolower($status), self::ENDED, true)) {
            return false;
        }

        return ($subscription['cancel_at_period_end'] ?? false) !== true;
    }

    private function configured(): bool
    {
        $url = config('billing-client.base_url');
        $token = config('billing-client.api_token');

        return is_string($url) && $url !== '' && is_string($token) && $token !== '';
    }
}
