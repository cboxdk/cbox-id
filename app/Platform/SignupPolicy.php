<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\ServiceAccess\ServiceAccessSettings;

/**
 * Identity signup follows the active environment's policy. Cbox's own identity
 * accounts and plain teams are independent of admission to the paid ID product.
 * Tenant environments retain their self-service switch; closed is a global kill switch.
 */
final readonly class SignupPolicy
{
    public function __construct(
        private PlaneResolver $planes,
        private SelfServiceSignup $selfService,
    ) {}

    public function mode(): string
    {
        $mode = config('cbox-id.signup.mode', 'open');

        return in_array($mode, ['open', 'invite_only', 'closed'], true) ? $mode : 'open';
    }

    public function isOpen(): bool
    {
        if ($this->onTenantEnvironment()) {
            return $this->mode() !== 'closed' && $this->selfService->enabledHere();
        }

        return $this->identityOnly() ? $this->mode() !== 'closed' : $this->mode() === 'open';
    }

    /** Plain identity teams may be created wherever identity signup is open. */
    public function allowsCreatingOrganizations(): bool
    {
        return $this->isOpen();
    }

    /** On the Cbox root an account is a person, not a purchased identity platform. */
    public function identityOnly(): bool
    {
        return $this->planes->onAccountPlane() || app(ServiceAccessSettings::class)->enabled();
    }

    /** Whether the switch that decides {@see isOpen()} here is the environment's own. */
    public function decidedByEnvironment(): bool
    {
        return $this->onTenantEnvironment();
    }

    public function closedMessage(): string
    {
        if ($this->onTenantEnvironment()) {
            return __('auth.signup.closed.tenant');
        }

        return $this->mode() === 'invite_only'
            ? __('auth.signup.closed.invite_only')
            : __('auth.signup.closed.closed');
    }

    /** A tenant environment on the SaaS shape — anywhere but the platform root. */
    private function onTenantEnvironment(): bool
    {
        return $this->planes->isMultiTenant() && ! $this->planes->onAccountPlane();
    }
}
