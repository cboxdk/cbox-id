<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Identity\ValueObjects\FederatedProvisioning;
use Cbox\Id\Identity\ValueObjects\Subject;
use Illuminate\Support\Facades\DB;

/** Keep subject mutations and their existing domain events in one commit. */
final readonly class SourceSubjects implements Subjects
{
    public function __construct(private Subjects $inner, private SourceLedger $ledger) {}

    /** Preserve the framework's storage-ownership check in its default eraser. */
    public function underlying(): Subjects
    {
        return $this->inner;
    }

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
        if ($this->ledger->environment() === null) {
            return $this->inner->create($email, $name, $password);
        }

        return DB::transaction(fn (): Subject => $this->inner->create($email, $name, $password));
    }

    /** {@inheritDoc} */
    public function provisionFederated(FederatedPrincipal $principal): Subject
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->provisionFederated($principal);
        }

        return DB::transaction(fn (): Subject => $this->inner->provisionFederated($principal));
    }

    /** {@inheritDoc} */
    public function resolveFederated(FederatedPrincipal $principal): FederatedProvisioning
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->resolveFederated($principal);
        }

        return DB::transaction(fn (): FederatedProvisioning => $this->inner->resolveFederated($principal));
    }

    /** {@inheritDoc} */
    public function link(string $subjectId, FederatedPrincipal $principal): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->link($subjectId, $principal);

            return;
        }
        DB::transaction(function () use ($subjectId, $principal): void {
            $this->inner->link($subjectId, $principal);
        });
    }

    /** {@inheritDoc} */
    public function linkedIdentities(string $subjectId): array
    {
        return $this->inner->linkedIdentities($subjectId);
    }

    /** {@inheritDoc} */
    public function unlink(string $subjectId, string $provider): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->unlink($subjectId, $provider);

            return;
        }
        DB::transaction(function () use ($subjectId, $provider): void {
            $this->inner->unlink($subjectId, $provider);
        });
    }

    /** {@inheritDoc} */
    public function verifyPassword(string $subjectId, string $password): bool
    {
        return $this->inner->verifyPassword($subjectId, $password);
    }

    /** {@inheritDoc} */
    public function setPassword(string $subjectId, string $password): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->setPassword($subjectId, $password);

            return;
        }
        DB::transaction(function () use ($subjectId, $password): void {
            $this->inner->setPassword($subjectId, $password);
        });
    }

    /** {@inheritDoc} */
    public function storeCredential(string $subjectId, string $passwordHash): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->storeCredential($subjectId, $passwordHash);

            return;
        }
        DB::transaction(function () use ($subjectId, $passwordHash): void {
            $this->inner->storeCredential($subjectId, $passwordHash);
        });
    }

    /** {@inheritDoc} */
    public function isActive(string $subjectId): bool
    {
        return $this->inner->isActive($subjectId);
    }

    /** {@inheritDoc} */
    public function deactivate(string $subjectId): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->deactivate($subjectId);

            return;
        }
        DB::transaction(function () use ($subjectId): void {
            $this->inner->deactivate($subjectId);
        });
    }

    /** {@inheritDoc} */
    public function reactivate(string $subjectId): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->reactivate($subjectId);

            return;
        }
        DB::transaction(function () use ($subjectId): void {
            $this->inner->reactivate($subjectId);
        });
    }

    /** {@inheritDoc} */
    public function update(string $subjectId, ?string $name = null, ?string $email = null): Subject
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->update($subjectId, $name, $email);
        }

        return DB::transaction(fn (): Subject => $this->inner->update($subjectId, $name, $email));
    }

    /** {@inheritDoc} */
    public function markEmailVerified(string $subjectId, string $email): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->markEmailVerified($subjectId, $email);

            return;
        }
        DB::transaction(function () use ($subjectId, $email): void {
            $this->inner->markEmailVerified($subjectId, $email);
        });
    }
}
