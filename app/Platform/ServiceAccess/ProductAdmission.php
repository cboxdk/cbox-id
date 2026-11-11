<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use App\Platform\Actions\ActionRefused;
use App\Platform\Billing\BillingSnapshots;
use App\Platform\Billing\Jobs\RefreshBillingSnapshot;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\PlatformRoot;

/**
 * This gate applies to buying the ID product, never to a tenant's identity users.
 *
 * Billing's answer is read from a local snapshot ({@see BillingSnapshots}): a page renders from
 * the last answer, yes or no, even while Billing is down, and a stale one is asked again in the
 * background. A change (a write method, or `$acting`) never acts on a kept answer: it asks Billing first.
 */
final readonly class ProductAdmission
{
    private const string POLICY_KEY = RefreshBillingSnapshot::POLICY;

    public function __construct(private BillingWaitlists $billing, private PlatformRoot $root, private BillingSnapshots $snapshots, private EnvironmentContext $environments) {}

    public function enabled(): bool
    {
        return ! app()->environment(['local', 'testing']) || config('service-access.product_enabled') === true;
    }

    public function assertWebGranted(string $subject, string $organization): void
    {
        try {
            $this->assertGranted($subject, $organization);
        } catch (ActionRefused $error) {
            abort($error->status, $error->getMessage());
        }
    }

    /**
     * @param  bool|null  $acting  true: the caller is about to change something, so Billing is asked first;
     *                             false: a read, which may be served a stale answer whatever the HTTP method
     *                             (an MCP read is a POST); null: read it off the HTTP method
     */
    public function assertGranted(string $subject, string $organization, ?bool $acting = null): void
    {
        if (! $this->enabled()) {
            return;
        }
        $key = self::key($subject, $organization);
        try {
            $allowed = $this->snapshots->remember(
                $key,
                fn (): bool => $this->ask($subject, $organization),
                static fn () => RefreshBillingSnapshot::admission($subject, $organization),
                $acting,
            );
            if (! is_bool($allowed)) {
                $allowed = $this->refresh($subject, $organization);
            }
        } catch (ServiceAccessUnavailable) {
            throw new ActionRefused('service_admission_unavailable', __('service_access.unavailable'), 503);
        }
        if (! $allowed) {
            throw new ActionRefused('service_admission_required', __('registration.access_required'), 403);
        }
    }

    /** Ask Billing now and keep the answer, yes or no. What a first visit waits for, and what the refresh job does. */
    public function refresh(string $subject, string $organization): bool
    {
        return $this->snapshots->refresh(self::key($subject, $organization), fn (): bool => $this->ask($subject, $organization));
    }

    /** ID itself let this person's organization in: the kept refusal no longer holds. */
    public function admitted(string $subject, string $organization): void
    {
        $this->snapshots->write(self::key($subject, $organization), true);
    }

    /** The product's signup policy, from the snapshot like admission. */
    public function policy(): RegistrationPolicy
    {
        if (! $this->enabled()) {
            return new RegistrationPolicy('', 'open', false, 'hidden', [], []);
        }
        $kept = $this->snapshots->remember(self::POLICY_KEY, $this->askPolicy(...), RefreshBillingSnapshot::policy(...));
        try {
            return RegistrationPolicy::from($kept);
        } catch (ServiceAccessUnavailable) {
            return $this->refreshPolicy();
        }
    }

    public function refreshPolicy(): RegistrationPolicy
    {
        return RegistrationPolicy::from($this->snapshots->refresh(self::POLICY_KEY, $this->askPolicy(...)));
    }

    /**
     * The policy is asked in the identity environment the product signup lives in, which a
     * queue worker has to enter itself.
     *
     * @return array<array-key, mixed>
     */
    private function askPolicy(): array
    {
        $environment = config('service-access.identity_environment');
        if (! is_string($environment) || $environment === '') {
            throw new ServiceAccessUnavailable('Service directory connection is not configured.');
        }

        return $this->environments->runAs(GenericEnvironment::of($environment), $this->billing->productPolicyData(...));
    }

    private function ask(string $subject, string $organization): bool
    {
        $allowed = $this->root->run(fn (): bool => $this->billing->productAllowed($subject, $organization));
        if ($allowed === null) {
            throw new ServiceAccessUnavailable('The platform root is not configured.');
        }

        return $allowed;
    }

    private static function key(string $subject, string $organization): string
    {
        return BillingSnapshots::key(RefreshBillingSnapshot::ADMISSION, [$subject, $organization]);
    }
}
