<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

use App\Platform\Invitations\AppReturnTargets;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * An app a person can create API keys for: one that declared an API key prefix, and that
 * their organization may use.
 *
 * `origins` are the web origins the app registered as redirect URIs — the only places a
 * `return_to` on the key page may send somebody back to. Same trust the authorization
 * endpoint extends to the app, and no more ({@see AppReturnTargets}).
 */
final readonly class KeyApp
{
    /**
     * @param  list<string>  $origins  `https://app.example`, normalised
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public string $prefix,
        public array $origins,
    ) {}

    public static function from(Client $client): self
    {
        $origins = [];

        foreach ($client->redirect_uris as $uri) {
            $origin = AppReturnTargets::origin($uri);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return new self(
            clientId: $client->client_id,
            name: $client->name,
            prefix: (string) $client->api_key_prefix,
            origins: array_values(array_unique($origins)),
        );
    }

    /**
     * `$url` when it sits on one of this app's registered origins, else null.
     *
     * Null rather than an error: the page still works without a way back, and a link that
     * points somewhere the app never registered is a phishing pivot with our domain on it.
     */
    public function returnTo(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $origin = AppReturnTargets::origin(trim($url));

        return $origin !== null && in_array($origin, $this->origins, true) ? trim($url) : null;
    }
}
