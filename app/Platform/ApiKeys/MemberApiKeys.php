<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys;

use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every API key the people of one organization hold, for the people who administer it.
 *
 * SEE AND REVOKE, NOT MINT. An administrator answers for what can act in their
 * organization, so they see every key in it — whose it is, which app it is for, what it may
 * do, when it was last used — and can stop any of them. They cannot create one for a
 * colleague: a key carries its holder's access, and a credential minted in somebody else's
 * name is exactly what this design keeps out ({@see HolderApiKeys}).
 */
final readonly class MemberApiKeys
{
    public function __construct(
        private CustomerApiKeys $keys,
        private BoundApiKeys $bound,
    ) {}

    /**
     * @return Collection<int, CustomerApiKey>
     */
    public function inOrganization(string $organizationId): Collection
    {
        return $this->keys->forOrganization($organizationId);
    }

    /**
     * Revoke a key in THIS organization. False when the id names no key here — including a
     * real key in another organization — or it was already revoked.
     */
    public function revoke(string $organizationId, string $keyId, ApiKeyActor $actor): bool
    {
        $key = $this->bound->find($organizationId, $keyId);

        return $key !== null && $this->keys->revoke($key->id, $actor);
    }
}
