<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys;

use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Models\CustomerApiKey;

/**
 * A key, found only where the person asking may reach it.
 *
 * The framework's {@see CustomerApiKeys::find()} looks a key up across every organization
 * in the environment — deliberately, because who may act on which key is the console's
 * decision. A revoke by the id in the URL alone would let one organization's administrator
 * stop another's integrations, and let one member stop a colleague's. So the id is never
 * trusted on its own: the organization, and the holder when a holder is asking, are part
 * of the WHERE clause, not a comparison made after a read that already found the row.
 *
 * Both are stated as columns AND the read runs under that organization's tenant, so the
 * binding holds whether or not tenant scoping happens to be on.
 */
final readonly class BoundApiKeys
{
    /**
     * The key `$keyId` in `$organizationId` — and held by `$holderId`, when given — or
     * null when it is not there to be reached.
     */
    public function find(string $organizationId, string $keyId, ?string $holderId = null): ?CustomerApiKey
    {
        return app(TenantContext::class)->runAs(
            GenericTenant::of($organizationId),
            fn (): ?CustomerApiKey => CustomerApiKey::query()
                ->whereKey($keyId)
                ->where('organization_id', $organizationId)
                ->when($holderId !== null, fn ($query) => $query->where('user_id', $holderId))
                ->first(),
        );
    }
}
