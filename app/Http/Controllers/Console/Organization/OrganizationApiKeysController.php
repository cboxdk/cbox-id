<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Props\Shared\AppApiKeyRows;
use App\Platform\ApiKeys\MemberApiKeys;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Inertia\Response;

/**
 * AN ORGANIZATION › API KEYS — every key its people have created for its apps, with what
 * each may do and when it was last used.
 *
 * Seen and revoked here (`api_keys.revoke`); never minted. A key carries its holder's access,
 * so a key made in somebody else's name would be a credential acting as a person who never
 * asked for it — each person creates their own under My account.
 */
final readonly class OrganizationApiKeysController extends OrganizationTabController
{
    public function index(MemberApiKeys $keys, AppApiKeyRows $rows): Response
    {
        $organization = $this->organization();

        return $this->page('environment/organizations/tabs/api-keys', $organization->name.' · API keys', [
            'apiKeys' => $rows->for(
                $keys->inOrganization($organization->id),
                withHolder: true,
                revokeHref: static fn (CustomerApiKey $key): string => route('environment.organizations.api-keys.revoke', ['organization' => $organization->id, 'key' => $key->id]),
            ),
        ]);
    }
}
