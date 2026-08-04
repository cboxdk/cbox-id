<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHOSE secrets the console is looking at — asked once, answered once.
 *
 * The vault's own boundary is {@see VaultOwner}: a typed (type, id) pair that every
 * mutating call on {@see SecretVault} takes, and that the
 * framework filters IN THE QUERY so a foreign id is indistinguishable from a missing one.
 * Deny-by-default, and correct — as long as the owner the caller passes is the caller's
 * OWN scope.
 *
 * The environment console passed `VaultOwner::fromRow($secret->owner_type, $secret->owner_id)`.
 * That reads the answer off the row being acted on and hands it back as the question, so
 * the check compared each row to itself and every row passed. A deny-by-default control
 * turned into a tautology by one convenient-looking constructor.
 *
 * So the owner comes from the SCOPE and never from the row, and it lives here rather than
 * in each of the three pages — the same reason {@see ConsoleScope} exists at all: three
 * private answers to one question is how two of them end up wrong.
 */
final class VaultScope
{
    public function __construct(private readonly ConsoleScope $scope) {}

    /**
     * The owner every read and every mutation is bounded by.
     *
     * Null is the ENVIRONMENT's own unowned secrets, which the vault addresses with
     * `whereNull('owner_type')` — a real and separate collection, not "all of them". It is
     * reachable only from the environment plane with no organization chosen, because
     * {@see ConsoleScope::organizationId()} refuses rather than answering null on the
     * organization plane.
     */
    public function owner(): ?VaultOwner
    {
        $organizationId = $this->scope->organizationId();

        return $organizationId === null ? null : VaultOwner::organization($organizationId);
    }

    /**
     * Secrets this console may see, narrowed the same way the framework narrows a
     * mutation — so what a page LISTS and what its buttons can act on cannot drift apart.
     *
     * @return Builder<VaultSecret>
     */
    public function secrets(): Builder
    {
        $owner = $this->owner();

        return VaultSecret::query()->when(
            $owner === null,
            static fn (Builder $query): Builder => $query->whereNull('owner_type')->whereNull('owner_id'),
            static fn (Builder $query): Builder => $query
                ->where('owner_type', $owner?->type->value)
                ->where('owner_id', $owner?->id),
        );
    }

    /**
     * One secret, or null — resolved through {@see secrets()} so an id belonging to
     * another organization is simply not found. The caller turns that into a 404, which
     * is the refusal that leaks nothing about what exists outside this scope.
     */
    public function find(string $secretId): ?VaultSecret
    {
        return $this->secrets()->whereKey($secretId)->first();
    }
}
