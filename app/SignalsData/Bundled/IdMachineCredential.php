<?php

declare(strict_types=1);

namespace App\SignalsData\Bundled;

use Cbox\Signals\Contracts\ManagedCredential;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Cbox ID identifies itself to Signals exactly like any other application: a
 * client-credentials token from the realm Signals trusts, requested over the
 * public token endpoint, cached until shortly before it expires. An empty
 * result means "not configured"; the client refuses to call Signals then.
 */
final readonly class IdMachineCredential implements ManagedCredential
{
    private const string CACHE_KEY = 'signals:bundled:machine-token';

    public function __construct(private Factory $http, private Repository $cache, private string $clientId, #[\SensitiveParameter] private string $clientSecret, private string $tokenUrl, private ?string $resource) {}

    public function token(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->tokenUrl === '') {
            return '';
        }
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $form = ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret];
        if ($this->resource !== null && $this->resource !== '') {
            $form['resource'] = $this->resource;
        }
        try {
            $response = $this->http->asForm()->acceptJson()->timeout(5)->withoutRedirecting()->post($this->tokenUrl, $form);
        } catch (ConnectionException) {
            return '';
        }
        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            return '';
        }
        $ttl = is_int($expires) ? max($expires - 60, 30) : 240;
        $this->cache->put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }
}
