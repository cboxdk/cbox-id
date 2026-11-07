<?php

declare(strict_types=1);

namespace Cbox\Signals\Testing;

use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Data\EnrollmentStatus;
use Cbox\Signals\Data\ManagedEnrollment;
use Cbox\Signals\Data\ManagedEnvironment;
use DateTimeImmutable;
use Throwable;

/**
 * In-memory enrolment store with the production contract's idempotency and
 * conflict rules, so an application can test its lifecycle hooks and the
 * scheduled reconcile without a Signals instance.
 */
class FakeManagedIntegration implements ManagedIntegration
{
    /** @var array<string,ManagedEnrollment> */
    public array $enrollments = [];

    /** @var list<array{provider:string,owner_id:string,secret_length:int}> */
    public array $enrollCalls = [];

    /** @var list<array{provider:string,owner_id:string}> */
    public array $revokeCalls = [];

    private ?Throwable $failure = null;

    /** @var list<string> */
    private array $failing = [];

    /** @param  list<string>  $methods  which of `enroll`, `enrollment`, `revoke` fail; all by default */
    public function failWith(?Throwable $failure, array $methods = ['enroll', 'enrollment', 'revoke']): self
    {
        $this->failure = $failure;
        $this->failing = $methods;

        return $this;
    }

    public function enroll(string $provider, ManagedEnvironment $environment, string $secret): ManagedEnrollment
    {
        $this->enrollCalls[] = ['provider' => $provider, 'owner_id' => $environment->ownerId, 'secret_length' => strlen($secret)];
        $this->raise('enroll');
        $key = $provider.'/'.$environment->ownerId;
        $now = new DateTimeImmutable;
        $current = $this->enrollments[$key] ?? null;
        $enrollment = new ManagedEnrollment($provider, $environment->ownerId, 'managed-'.str_replace('cbox_', '', $provider).'-'.$environment->ownerId, $environment->url, 1, EnrollmentStatus::Enrolled, $environment->importFrom, $current === null ? $now : $current->enrolledAt, $now, null, null, null);
        $this->enrollments[$key] = $enrollment;

        return $enrollment;
    }

    public function enrollment(string $provider, string $ownerId): ?ManagedEnrollment
    {
        $this->raise('enrollment');

        return $this->enrollments[$provider.'/'.$ownerId] ?? null;
    }

    public function revoke(string $provider, string $ownerId): void
    {
        $this->revokeCalls[] = ['provider' => $provider, 'owner_id' => $ownerId];
        $this->raise('revoke');
        $key = $provider.'/'.$ownerId;
        $current = $this->enrollments[$key] ?? null;
        if ($current !== null) {
            $now = new DateTimeImmutable;
            $this->enrollments[$key] = new ManagedEnrollment($provider, $ownerId, $current->source, $current->url, $current->catalogVersion, EnrollmentStatus::Revoked, $current->importFrom, $current->enrolledAt, $now, $now, $current->pipelinesGeneration, $current->errorCode);
        }
    }

    private function raise(string $method): void
    {
        if ($this->failure !== null && in_array($method, $this->failing, true)) {
            throw $this->failure;
        }
    }
}
