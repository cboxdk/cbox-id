<?php

declare(strict_types=1);

namespace App\Platform\Radar\IpIntelligence;

/**
 * One address, as an intelligence source describes it. Every field may be unknown.
 *
 * The flags are what the source reports, not what Radar infers: a free GeoLite2 database
 * reports none of them, and `false` from it means "not reported", which is why the rules
 * that need them say so.
 */
final readonly class IpProfile
{
    public function __construct(
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $asn = null,
        public ?string $asOrganization = null,
        public bool $hosting = false,
        public bool $vpn = false,
        public bool $proxy = false,
        public bool $tor = false,
    ) {}

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * The form the lookup cache holds.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'asn' => $this->asn,
            'as_organization' => $this->asOrganization,
            'hosting' => $this->hosting,
            'vpn' => $this->vpn,
            'proxy' => $this->proxy,
            'tor' => $this->tor,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $float = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        $country = $data['country'] ?? null;
        $asn = $data['asn'] ?? null;
        $org = $data['as_organization'] ?? null;

        return new self(
            country: is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) === 1 ? strtoupper($country) : null,
            latitude: $float($data['latitude'] ?? null),
            longitude: $float($data['longitude'] ?? null),
            asn: is_int($asn) ? $asn : null,
            asOrganization: is_string($org) && $org !== '' ? $org : null,
            hosting: ($data['hosting'] ?? false) === true,
            vpn: ($data['vpn'] ?? false) === true,
            proxy: ($data['proxy'] ?? false) === true,
            tor: ($data['tor'] ?? false) === true,
        );
    }

    /** The same profile, with Tor known from elsewhere (the local exit list). */
    public function withTor(bool $tor): self
    {
        return new self($this->country, $this->latitude, $this->longitude, $this->asn, $this->asOrganization, $this->hosting, $this->vpn, $this->proxy, $this->tor || $tor);
    }
}
