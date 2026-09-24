<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Whether self-service signup is available HERE. Admin invitations and operator
 * provisioning are separate and never gated here.
 *
 * TWO QUESTIONS, depending on the shape and the host:
 *
 *  - single-tenant, and the platform root of the SaaS shape: `cbox-id.signup.mode`, the
 *    deployment's own setting. On the root that is "may a stranger buy an identity
 *    platform"; on a single-tenant install it is "may a stranger join this one".
 *  - a TENANT environment on the SaaS shape: the environment's own switch
 *    ({@see SelfServiceSignup}), because whether a vendor's app lets its end users sign
 *    themselves up is the vendor's decision. The deployment mode still acts as the
 *    operator's kill switch — `closed` closes every door on the deployment — but
 *    `invite_only` is about the root's own signups and does not reach into a customer's
 *    environment.
 *
 * The tenant half used to be the deployment mode too, while `/signup` itself 404'd on every
 * tenant host. So a tenant's sign-in page offered "Create an account" linking to a page
 * that did not exist, and a magic link — which provisions on first use — minted an account
 * for any address on every tenant, with no setting anywhere its owner could reach. Both now
 * follow the environment's switch, which is off until somebody turns it on.
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

        return $this->mode() === 'open';
    }

    /**
     * Whether a signed-in person may found an organization of their own from an app
     * (`prompt=create_organization`).
     *
     * Exactly when a stranger could have signed up here and got one — the signup form
     * creates the signer's organization, so a person who already has an account is asked
     * nothing that a new one was not. Never on the SaaS platform root: an organization
     * there is a customer account with a project and a bill behind it, and it is created
     * by buying one, not by an app.
     */
    public function allowsCreatingOrganizations(): bool
    {
        if ($this->planes->onAccountPlane()) {
            return false;
        }

        return $this->isOpen();
    }

    /** Whether the switch that decides {@see isOpen()} here is the environment's own. */
    public function decidedByEnvironment(): bool
    {
        return $this->onTenantEnvironment();
    }

    public function closedMessage(): string
    {
        if ($this->onTenantEnvironment()) {
            return 'You need an invitation to join. Ask the person who runs your team for one.';
        }

        return $this->mode() === 'invite_only'
            ? 'Signups are invite-only. Ask an administrator for an invitation.'
            : 'Signups are currently closed.';
    }

    /** A tenant environment on the SaaS shape — anywhere but the platform root. */
    private function onTenantEnvironment(): bool
    {
        return $this->planes->isMultiTenant() && ! $this->planes->onAccountPlane();
    }
}
