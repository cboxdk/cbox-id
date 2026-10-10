<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * IPinfo's API, with the operator's own token.
 *
 * One HTTPS request per address the cache has not seen ({@see CachingIpIntelligence} sits in
 * front of it), bounded by a short timeout because it runs INSIDE a sign-in. The address is
 * sent to IPinfo — that is what the operator chose by choosing this driver, and the guide
 * says so. The `privacy` block (VPN, proxy, Tor, hosting) is only in IPinfo's paid plans;
 * without it those flags are simply not reported.
 *
 * Fails open: a timeout, a non-2xx, or a body that is not what IPinfo sends is "unknown".
 */
final readonly class IpinfoIpIntelligence implements IpIntelligence
{
    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $baseUrl = 'https://ipinfo.io',
        private float $timeout = 1.5,
    ) {}

    public function lookup(string $ip): ?IpProfile
    {
        if ($this->token === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $response = $this->http->timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->acceptJson()
                ->withToken($this->token)
                ->get(rtrim($this->baseUrl, '/').'/'.rawurlencode($ip).'/json');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ($body['bogon'] ?? false) === true) {
            return null;
        }

        [$latitude, $longitude] = self::location($body['loc'] ?? null);
        [$asn, $organization] = self::network($body);
        $privacy = is_array($body['privacy'] ?? null) ? $body['privacy'] : [];

        return IpProfile::fromArray([
            'country' => $body['country'] ?? null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'asn' => $asn,
            'as_organization' => $organization,
            'hosting' => ($privacy['hosting'] ?? false) === true,
            'vpn' => ($privacy['vpn'] ?? false) === true,
            'proxy' => ($privacy['proxy'] ?? false) === true || ($privacy['relay'] ?? false) === true,
            'tor' => ($privacy['tor'] ?? false) === true,
        ]);
    }

    /**
     * `"55.6759,12.5655"`.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function location(mixed $loc): array
    {
        if (! is_string($loc) || preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $loc, $match) !== 1) {
            return [null, null];
        }

        return [(float) $match[1], (float) $match[2]];
    }

    /**
     * The network: an `asn` object on the paid plans, an `org` string ("AS15169 Google LLC")
     * on the free one.
     *
     * @param  array<mixed>  $body
     * @return array{0: int|null, 1: string|null}
     */
    private static function network(array $body): array
    {
        $asn = is_array($body['asn'] ?? null) ? $body['asn'] : null;

        if ($asn !== null && is_string($asn['asn'] ?? null) && preg_match('/^AS(\d+)$/', $asn['asn'], $match) === 1) {
            return [(int) $match[1], is_string($asn['name'] ?? null) ? $asn['name'] : null];
        }

        $org = $body['org'] ?? null;

        if (is_string($org) && preg_match('/^AS(\d+)\s*(.*)$/', $org, $match) === 1) {
            return [(int) $match[1], $match[2] !== '' ? $match[2] : null];
        }

        return [null, null];
    }
}
