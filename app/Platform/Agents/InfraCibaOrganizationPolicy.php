<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use Cbox\Id\OAuthServer\Contracts\CibaOrganizationPolicy;
use Cbox\Id\OAuthServer\Models\Client;

/** Only the registered Infra approval client/resource in its issuer environment opts in. */
final class InfraCibaOrganizationPolicy implements CibaOrganizationPolicy
{
    public function allows(Client $client, string $organizationId, string $resource): bool
    {
        return $client->environment_id !== '' && $client->client_id !== '' && $resource !== ''
            && config('ciba.infra.environment_id') === $client->environment_id
            && config('ciba.infra.client_id') === $client->client_id
            && config('ciba.infra.resource') === $resource;
    }
}
