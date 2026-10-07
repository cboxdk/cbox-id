<?php

declare(strict_types=1);

namespace App\Platform\Apis;

use Cbox\Id\OAuthServer\Models\Client;

/**
 * Which app an API may be linked to: the API's own owner's app, and never one that
 * registered itself.
 *
 * Tokens for an API carry the linked app's roles and permissions, so linking another
 * owner's app would stamp one owner's authorization model into tokens for another's API.
 * The framework refuses the same thing; asked here first so the refusal names the field and
 * says why, the same from the console and from the API.
 */
final class ApiLinks
{
    /** Why $clientId cannot be linked to an API owned by $organizationId, or null when it can. */
    public static function refusal(?string $clientId, ?string $organizationId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $client = Client::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            return 'Choose one of the apps offered.';
        }

        if ($client->organization_id !== $organizationId || ($organizationId === null && $client->isDynamicallyRegistered())) {
            return 'Link an app with the same owner as the API. Tokens for this API carry the linked app\'s roles and permissions, so it has to be the owner\'s own app.';
        }

        return null;
    }
}
