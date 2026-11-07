<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Data\IssuedSecret;
use Cbox\Signals\Data\ManagedEnvironment;

/**
 * What the source application contributes to its bundled integration: which
 * environments are live, which were retired, and a read secret scoped to the
 * Signals export only. The reconciler owns everything else.
 */
interface ManagedSources
{
    /** Catalog provider id for this application. */
    public function provider(): string;

    /** @return iterable<ManagedEnvironment> environments that should be enrolled */
    public function environments(): iterable;

    /** @return iterable<string> owner ids whose enrolment must be revoked (deleted or archived environments) */
    public function retired(): iterable;

    /** Issue a fresh read secret for the environment's Signals export. */
    public function issueSecret(ManagedEnvironment $environment): IssuedSecret;

    /**
     * Retire every previously issued Signals read secret for the environment
     * except the one referenced. Called after Signals has accepted the new one.
     */
    public function retireSecrets(ManagedEnvironment $environment, string $keepReference): void;

    /**
     * Retire one secret Signals never accepted (the enrolment failed after it
     * was issued). Existing secrets stay valid.
     */
    public function discardSecret(ManagedEnvironment $environment, string $reference): void;
}
