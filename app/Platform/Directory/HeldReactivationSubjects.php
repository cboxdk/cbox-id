<?php

declare(strict_types=1);

namespace App\Platform\Directory;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Identity\ValueObjects\FederatedProvisioning;
use Cbox\Id\Identity\ValueObjects\Subject;

/**
 * The subjects contract as the framework's directory sync sees it: a reactivation the
 * running push may not make is held. Every other call passes straight through. See
 * {@see DirectoryReactivationHold}.
 */
final readonly class HeldReactivationSubjects implements Subjects
{
    public function __construct(private Subjects $inner, private DirectoryReactivationHold $hold) {}

    /** {@inheritDoc} */
    public function find(string $id): ?Subject
    {
        return $this->inner->find($id);
    }

    /** {@inheritDoc} */
    public function findMany(array $ids): array
    {
        return $this->inner->findMany($ids);
    }

    /** {@inheritDoc} */
    public function findByEmail(string $email): ?Subject
    {
        return $this->inner->findByEmail($email);
    }

    /** {@inheritDoc} */
    public function create(string $email, ?string $name = null, ?string $password = null): Subject
    {
        return $this->inner->create($email, $name, $password);
    }

    /** {@inheritDoc} */
    public function provisionFederated(FederatedPrincipal $principal): Subject
    {
        return $this->inner->provisionFederated($principal);
    }

    /** {@inheritDoc} */
    public function resolveFederated(FederatedPrincipal $principal): FederatedProvisioning
    {
        return $this->inner->resolveFederated($principal);
    }

    /** {@inheritDoc} */
    public function link(string $subjectId, FederatedPrincipal $principal): void
    {
        $this->inner->link($subjectId, $principal);
    }

    /** {@inheritDoc} */
    public function linkedIdentities(string $subjectId): array
    {
        return $this->inner->linkedIdentities($subjectId);
    }

    /** {@inheritDoc} */
    public function unlink(string $subjectId, string $provider): void
    {
        $this->inner->unlink($subjectId, $provider);
    }

    /** {@inheritDoc} */
    public function verifyPassword(string $subjectId, string $password): bool
    {
        return $this->inner->verifyPassword($subjectId, $password);
    }

    /** {@inheritDoc} */
    public function setPassword(string $subjectId, string $password): void
    {
        $this->inner->setPassword($subjectId, $password);
    }

    /** {@inheritDoc} */
    public function storeCredential(string $subjectId, string $passwordHash): void
    {
        $this->inner->storeCredential($subjectId, $passwordHash);
    }

    /** {@inheritDoc} */
    public function isActive(string $subjectId): bool
    {
        return $this->inner->isActive($subjectId);
    }

    /** {@inheritDoc} */
    public function deactivate(string $subjectId): void
    {
        $this->inner->deactivate($subjectId);
    }

    /** {@inheritDoc} */
    public function reactivate(string $subjectId): void
    {
        // A directory push that may not undo this deactivation leaves it in place.
        if (! $this->hold->allows()) {
            return;
        }

        $this->inner->reactivate($subjectId);
    }

    /** {@inheritDoc} */
    public function update(string $subjectId, ?string $name = null, ?string $email = null): Subject
    {
        return $this->inner->update($subjectId, $name, $email);
    }

    /** {@inheritDoc} */
    public function markEmailVerified(string $subjectId, string $email): void
    {
        $this->inner->markEmailVerified($subjectId, $email);
    }
}
