<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

use MaxMind\Db\Reader;
use Throwable;

/**
 * MaxMind's GeoLite2 / GeoIP2 databases, read from LOCAL FILES.
 *
 * Nothing is fetched at runtime: the operator downloads the databases under MaxMind's own
 * licence (GeoLite2 is free with an account and attribution; GeoIP2 is paid) and points the
 * config at them. Each file is optional and adds what it holds:
 *
 *  - City (GeoLite2-City / GeoIP2-City): country and an approximate point;
 *  - ASN (GeoLite2-ASN / GeoIP2-ISP): the network number and its owner;
 *  - Anonymous IP (GeoIP2-Anonymous-IP, paid): VPN, hosting, public proxy and Tor flags.
 *
 * Readers are opened lazily, once per process, and a file that is missing or unreadable is
 * simply not consulted — fail open, as {@see IpIntelligence} requires.
 */
final class MaxMindIpIntelligence implements IpIntelligence
{
    /** @var array<string, Reader|false> */
    private array $readers = [];

    public function __construct(
        private readonly ?string $cityDatabase,
        private readonly ?string $asnDatabase,
        private readonly ?string $anonymousDatabase,
    ) {}

    public function lookup(string $ip): ?IpProfile
    {
        $city = $this->record($this->cityDatabase, $ip);
        $asn = $this->record($this->asnDatabase, $ip);
        $anonymous = $this->record($this->anonymousDatabase, $ip);

        if ($city === null && $asn === null && $anonymous === null) {
            return null;
        }

        return IpProfile::fromArray([
            'country' => self::path($city, 'country', 'iso_code') ?? self::path($city, 'registered_country', 'iso_code'),
            'latitude' => self::path($city, 'location', 'latitude'),
            'longitude' => self::path($city, 'location', 'longitude'),
            'asn' => $asn['autonomous_system_number'] ?? null,
            'as_organization' => $asn['autonomous_system_organization'] ?? null,
            'hosting' => ($anonymous['is_hosting_provider'] ?? false) === true,
            'vpn' => ($anonymous['is_anonymous_vpn'] ?? false) === true,
            'proxy' => ($anonymous['is_public_proxy'] ?? false) === true || ($anonymous['is_residential_proxy'] ?? false) === true,
            'tor' => ($anonymous['is_tor_exit_node'] ?? false) === true,
        ]);
    }

    /**
     * @return array<mixed>|null
     */
    private function record(?string $path, string $ip): ?array
    {
        $reader = $this->reader($path);

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }

        return is_array($record) ? $record : null;
    }

    private function reader(?string $path): ?Reader
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (! array_key_exists($path, $this->readers)) {
            try {
                $this->readers[$path] = is_readable($path) ? new Reader($path) : false;
            } catch (Throwable) {
                $this->readers[$path] = false;
            }
        }

        $reader = $this->readers[$path];

        return $reader === false ? null : $reader;
    }

    /**
     * @param  array<mixed>|null  $record
     */
    private static function path(?array $record, string $outer, string $inner): mixed
    {
        $value = $record[$outer] ?? null;

        return is_array($value) ? ($value[$inner] ?? null) : null;
    }
}
