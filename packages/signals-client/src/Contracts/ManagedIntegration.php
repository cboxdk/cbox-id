<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Data\ManagedEnrollment;
use Cbox\Signals\Data\ManagedEnvironment;
use Cbox\Signals\Exceptions\SignalsFailure;

/**
 * Bundled Signals integration for a first-party source application.
 *
 * The application enrols each of its environments once; Signals finds or
 * creates the organization's project, stores the read secret encrypted and
 * registers the catalog's default shadow pipelines. Enrolment is idempotent:
 * the same environment twice yields the same enrolment, a new secret is a
 * rotation, a changed source URL is a conflict (`enrollment_conflict`).
 */
interface ManagedIntegration
{
    /**
     * @param  string  $provider  catalog provider id (`cbox_id`, `cbox_billing`, `stache`)
     * @param  string  $secret  one-time plaintext read secret; transported once, never logged
     *
     * @throws SignalsFailure `not_configured`, `unavailable`, `enrollment_conflict`, `organization_unknown`, `malformed_response`
     */
    public function enroll(string $provider, ManagedEnvironment $environment, string $secret): ManagedEnrollment;

    /**
     * @return ManagedEnrollment|null null when the environment was never enrolled
     *
     * @throws SignalsFailure
     */
    public function enrollment(string $provider, string $ownerId): ?ManagedEnrollment;

    /**
     * Revoking removes the grant and stops pulling within one worker interval.
     * Revoking an unknown enrolment is not an error.
     *
     * @throws SignalsFailure
     */
    public function revoke(string $provider, string $ownerId): void;
}
