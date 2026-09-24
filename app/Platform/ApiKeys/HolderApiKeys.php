<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys;

use App\Platform\ApiKeys\ValueObjects\KeyOrganization;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\ApiKeyRefusal;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Exceptions\CustomerApiKeyRefused;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;
use Cbox\Id\Organization\ValueObjects\IssuedCustomerApiKey;
use Cbox\Id\Organization\ValueObjects\NewCustomerApiKey;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * A person's own API keys: the organizations they can hold one in, the keys they hold,
 * minting one, and revoking one.
 *
 * EVERYTHING IS KEYED TO THE HOLDER, never to a parameter. The organization and the key id
 * arrive from the page, which is the client's; the person is the signed-in subject.
 *
 * NOBODY MINTS FOR ANYONE ELSE. The holder issues their own keys, which is why the actor
 * on every issuance is the holder. An administrator can see and revoke keys in their
 * organization ({@see MemberApiKeys}) but cannot create one in a colleague's name: a key
 * carries its holder's access, and handing somebody a credential that acts as another
 * person is the thing this whole design exists to avoid.
 */
final readonly class HolderApiKeys
{
    public function __construct(
        private CustomerApiKeys $keys,
        private KeyApps $apps,
        private Memberships $memberships,
        private Organizations $organizations,
        private BoundApiKeys $bound,
    ) {}

    /**
     * The organizations this person can hold a key in: active memberships of organizations
     * that are themselves active. The framework refuses the rest at issuance anyway; not
     * offering them is what keeps the page from inviting a refusal.
     *
     * @return list<KeyOrganization>
     */
    public function organizationsOf(string $userId): array
    {
        $ids = $this->memberships->forUser($userId)
            ->filter(static fn (Membership $membership): bool => $membership->status === MembershipStatus::Active)
            ->map(static fn (Membership $membership): string => (string) $membership->organization_id)
            ->values()
            ->all();

        $organizations = [];

        foreach ($this->organizations->findMany($ids) as $id => $organization) {
            if (! $organization->status->revokesAccess()) {
                $organizations[] = new KeyOrganization((string) $id, $organization->name);
            }
        }

        usort($organizations, static fn (KeyOrganization $a, KeyOrganization $b): int => strcasecmp($a->name, $b->name));

        return $organizations;
    }

    /**
     * This person's keys in one organization, every app, newest first — revoked and
     * expired ones included, so a key that stopped working still says why.
     *
     * @return Collection<int, CustomerApiKey>
     */
    public function held(string $organizationId, string $userId): Collection
    {
        return $this->keys->forUser($organizationId, $userId);
    }

    /**
     * Mint a key for the holder.
     *
     * The app must be one this organization may use; the framework then checks the rest —
     * active membership, active organization and account, and every permission one the
     * holder holds for the app right now.
     *
     * @param  list<string>  $permissions
     *
     * @throws CustomerApiKeyRefused
     */
    public function issue(
        string $userId,
        string $organizationId,
        string $clientId,
        array $permissions,
        ?string $name,
        ?DateTimeInterface $expiresAt,
    ): IssuedCustomerApiKey {
        if ($this->apps->offered($organizationId, $clientId) === null) {
            throw CustomerApiKeyRefused::because(
                ApiKeyRefusal::UnknownClient,
                "App [{$clientId}] does not offer API keys to organization [{$organizationId}].",
            );
        }

        return $this->keys->issue(new NewCustomerApiKey(
            organizationId: $organizationId,
            userId: $userId,
            clientId: $clientId,
            permissions: $permissions,
            name: $name,
            expiresAt: $expiresAt,
        ), ApiKeyActor::user($userId));
    }

    /**
     * Revoke one of the holder's own keys. False when there is no such key of theirs in
     * that organization, or it was already revoked.
     */
    public function revoke(string $organizationId, string $userId, string $keyId): bool
    {
        $key = $this->bound->find($organizationId, $keyId, holderId: $userId);

        return $key !== null && $this->keys->revoke($key->id, ApiKeyActor::user($userId));
    }
}
