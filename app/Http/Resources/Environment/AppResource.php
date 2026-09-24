<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use App\Platform\AppKind;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * An app (OAuth client) — `App` in the spec.
 *
 * `type` is the kind the console would call it (`web`, `spa`, `cli`, `service`, `agent`,
 * or `advanced` when its grants match no preset), read back from its grants rather than
 * stored; `client_type` is the OAuth type that decides whether it holds a secret.
 *
 * `client_secret` is present ONLY on the response that created the app — the server keeps
 * a hash, so this is the one chance to read it.
 */
final class AppResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Client $client, ?string $secret = null): array
    {
        $out = [
            'id' => $client->id,
            'client_id' => $client->client_id,
            'name' => $client->name,
            'type' => AppKind::forClient($client)->value,
            'client_type' => $client->type->value,
            'organization_id' => $client->organization_id,
            'first_party' => $client->first_party,
            'grant_types' => array_values($client->grant_types),
            'redirect_uris' => array_values($client->redirect_uris),
            'post_logout_redirect_uris' => array_values($client->post_logout_redirect_uris ?? []),
            'scopes' => array_values($client->scopes),
            'created_at' => Timestamp::of($client->getAttribute('created_at')),
        ];

        if ($secret !== null && $secret !== '') {
            $out['client_secret'] = $secret;
        }

        return $out;
    }
}
