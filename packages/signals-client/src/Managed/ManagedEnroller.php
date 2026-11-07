<?php

declare(strict_types=1);

namespace Cbox\Signals\Managed;

use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Contracts\ManagedSources;
use Cbox\Signals\Data\ManagedEnvironment;
use Cbox\Signals\Data\ManagedReconciliation;
use Cbox\Signals\Exceptions\SignalsFailure;

/**
 * Brings Signals' view of the application's environments in line with the
 * application's own. Enrols what is missing, rotates when the application
 * asks for it, revokes what the application has explicitly retired, and
 * leaves matching enrolments alone so a scheduled run is free of side effects.
 *
 * A failure on one environment never blocks the others. Secrets are issued
 * only when Signals is about to receive them and are retired only after
 * Signals has acknowledged the replacement.
 */
final readonly class ManagedEnroller
{
    public function __construct(private ManagedIntegration $signals, private ManagedSources $sources) {}

    /** @param  list<string>  $rotate  owner ids whose secret must be replaced even when enrolled */
    public function reconcile(array $rotate = []): ManagedReconciliation
    {
        $provider = $this->sources->provider();
        $enrolled = $rotated = $unchanged = $revoked = [];
        $failed = [];

        foreach ($this->sources->environments() as $environment) {
            try {
                $current = $this->signals->enrollment($provider, $environment->ownerId);
                if ($current !== null && $current->active() && $current->url === $environment->url && ! in_array($environment->ownerId, $rotate, true)) {
                    $unchanged[] = $environment->ownerId;

                    continue;
                }
                $this->install($provider, $environment);
                if ($current !== null && $current->active()) {
                    $rotated[] = $environment->ownerId;
                } else {
                    $enrolled[] = $environment->ownerId;
                }
            } catch (SignalsFailure $failure) {
                $failed[$environment->ownerId] = $failure->errorCode;
            }
        }

        foreach ($this->sources->retired() as $ownerId) {
            try {
                $this->signals->revoke($provider, $ownerId);
                $revoked[] = $ownerId;
            } catch (SignalsFailure $failure) {
                $failed[$ownerId] = $failure->errorCode;
            }
        }

        return new ManagedReconciliation($enrolled, $rotated, $unchanged, $revoked, $failed);
    }

    /** Enrol or rotate one environment immediately (for lifecycle hooks). */
    public function enroll(ManagedEnvironment $environment): void
    {
        $this->install($this->sources->provider(), $environment);
    }

    /** Revoke one environment immediately (for lifecycle hooks). */
    public function revoke(string $ownerId): void
    {
        $this->signals->revoke($this->sources->provider(), $ownerId);
    }

    private function install(string $provider, ManagedEnvironment $environment): void
    {
        $secret = $this->sources->issueSecret($environment);
        try {
            $this->signals->enroll($provider, $environment, $secret->plaintext);
        } catch (SignalsFailure $failure) {
            $this->sources->discardSecret($environment, $secret->reference);
            throw $failure;
        }
        $this->sources->retireSecrets($environment, $secret->reference);
    }
}
