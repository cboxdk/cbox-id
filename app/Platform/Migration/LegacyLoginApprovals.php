<?php

declare(strict_types=1);

namespace App\Platform\Migration;

use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;

/**
 * Approving — or withdrawing — the legacy login an app declared.
 *
 * The framework stores the declaration and refuses to act on it; deciding whether it may
 * act is this deployment's policy, so it lives here. That split is the point: a package
 * that both accepted a URL from a client AND enabled it would have no place left to put a
 * human.
 *
 * Approval is one row and two columns, and it is written through a service rather than
 * from a Livewire component because it is the moment a URL joins the sign-in path. Who
 * did it is recorded beside when, because "who agreed to send passwords there" is the
 * first question anybody asks afterwards.
 */
final class LegacyLoginApprovals
{
    /**
     * The declaration on file for the current environment, or null.
     *
     * One per environment by construction — the sign-in path is the environment's, so two
     * apps cannot each nominate a different place to send passwords.
     */
    public function current(): ?LegacyLoginDeclarationRecord
    {
        $record = LegacyLoginDeclarationRecord::query()->first();

        return $record instanceof LegacyLoginDeclarationRecord ? $record : null;
    }

    /**
     * Put the declared endpoint into the sign-in path.
     *
     * Re-approving an already-approved declaration keeps the ORIGINAL timestamp: when a
     * URL joined the login path is a fact somebody needs during an incident, and
     * overwriting it with the time of a second click destroys it.
     */
    public function approve(string $actorId): void
    {
        LegacyLoginDeclarationRecord::query()
            ->whereNull('approved_at')
            ->update(['approved_at' => now(), 'approved_by' => $actorId]);
    }

    /**
     * Take it back out, without deleting the declaration.
     *
     * The row stays so the console keeps showing what the app is asking for — an operator
     * who revokes during an incident should still be able to see what they revoked, and
     * the app will re-declare it on its next deploy regardless.
     */
    public function revoke(): void
    {
        LegacyLoginDeclarationRecord::query()
            ->whereNotNull('approved_at')
            ->update(['approved_at' => null, 'approved_by' => null]);
    }
}
