<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;

/**
 * In the Cbox realm nobody goes on to an app or the console without an organization.
 *
 * A person can end up with none: an administrator or a directory removed their last
 * membership, their only organization was closed, or they signed up before personal
 * organizations existed. Their account is still theirs, so they are not refused. They are
 * asked once, on one screen, to accept an invitation or continue with an organization of
 * their own, and then carry on to where they were going.
 *
 * The doors that ask this: `/oauth/authorize` before it binds an organization, and the
 * console's landings (`/dashboard`, the last-used organization, an organization's front
 * door). Off unless {@see PersonalOrganizations::enabled()}.
 */
final readonly class OrganizationRequired
{
    public function __construct(
        private PersonalOrganizations $personal,
        private AuthorizationOrganizations $organizations,
        private ConsoleScope $scope,
        private Impersonation $impersonation,
    ) {}

    /**
     * Whether this signed-in person must pass the "no organization" screen first.
     *
     * "No organization" is the answer apps get: no ACTIVE membership of a LIVE organization
     * in this environment. Never an operator, whose work is the deployment and needs
     * none, and never inside a support session, which must not found or join anything on
     * the person's behalf.
     */
    public function appliesTo(CurrentUser $me): bool
    {
        return $this->personal->enabled()
            && $me->check()
            && ! $this->impersonation->isImpersonating()
            && ! $this->scope->isPlatformOperator()
            && $this->organizations->choicesFor($me->id()) === [];
    }

    public function url(): string
    {
        return route('organization.required');
    }
}
