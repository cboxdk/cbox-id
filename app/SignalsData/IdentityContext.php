<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;
use App\Platform\Radar\IpIntelligence\OfflineIpIntelligence;
use App\Platform\Radar\RadarDevices;
use Illuminate\Http\Request;
use Throwable;

final readonly class IdentityContext
{
    public const string PROFILE = 'signals.source.ip_profile';

    private const string LOOKUPS = 'signals.source.ip_lookups';

    public function __construct(private RadarDevices $devices, private IpIntelligence $intelligence) {}

    /** @return array<string, string|null> */
    public static function unknown(): array
    {
        return [...array_fill_keys(['device_id', 'ip', 'country', 'asn', 'browser_family', 'browser_major', 'os_family', 'geo_accuracy_km', 'latitude', 'longitude'], null),
            'device_evidence' => 'missing', 'network_class' => 'unknown', 'enrichment_version' => 'id-context-2'];
    }

    /** @return array<string, int|float|string|null> */
    public function collect(Request $request, ?string $ip = null, ?string $userAgent = null): array
    {
        $ip ??= $request->ip();
        $ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        $device = $ip !== null && $ip === $request->ip() ? $this->devices->identify($request) : null;
        $profile = $this->profile($request, $ip);
        $ua = substr($userAgent ?? (string) $request->userAgent(), 0, 2048);
        $browser = $major = null;
        foreach (['Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/', 'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/', 'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/', 'Safari' => '/Version\/(\d+).*Safari/'] as $family => $pattern) {
            if (preg_match($pattern, $ua, $match) === 1) {
                $browser = $family;
                $major = $match[1];
                break;
            }
        }
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $located = $profile?->accuracyKm !== null && $profile->hasLocation()
            && abs($profile->latitude ?? 91) <= 90 && abs($profile->longitude ?? 181) <= 180;

        return [
            'device_id' => $device?->deviceHash, 'device_evidence' => $device === null ? 'missing' : 'first_party_cookie',
            'ip' => $ip, 'country' => $profile?->country, 'asn' => $profile?->asn,
            'browser_family' => $browser, 'browser_major' => $major, 'os_family' => $os,
            'network_class' => match (true) {
                $profile?->tor === true => 'tor', $profile?->vpn === true => 'vpn', $profile?->hosting === true => 'hosting',
                default => 'unknown',
            },
            // Round to about 11 km and expand the source radius by the maximum
            // rounding displacement. Providers without accuracy remain unknown.
            'latitude' => $located ? round($profile->latitude ?? 0, 1) : null,
            'longitude' => $located ? round($profile->longitude ?? 0, 1) : null,
            'geo_accuracy_km' => $located ? ceil($profile->accuracyKm ?? 0) + 8 : null,
            'enrichment_version' => 'id-context-2',
        ];
    }

    private function profile(Request $request, ?string $ip): ?IpProfile
    {
        if ($ip === null) {
            return null;
        }

        // Radar already resolved this request's address, including an unknown
        // result. A session's explicit address must never inherit that profile.
        if ($ip === $request->ip() && $request->attributes->has(self::PROFILE)) {
            $profile = $request->attributes->get(self::PROFILE);

            return $profile instanceof IpProfile ? $profile : null;
        }

        $lookups = $request->attributes->get(self::LOOKUPS);
        $lookups = is_array($lookups) ? $lookups : [];
        if (! array_key_exists($ip, $lookups)) {
            try {
                $lookups[$ip] = $this->intelligence instanceof OfflineIpIntelligence
                    ? $this->intelligence->lookupOffline($ip) : null;
            } catch (Throwable) {
                // A missing provider/cache must not roll back the owning ID
                // mutation or turn absent evidence into a country or ASN.
                $lookups[$ip] = null;
            }
            $request->attributes->set(self::LOOKUPS, $lookups);
        }

        return $lookups[$ip] instanceof IpProfile ? $lookups[$ip] : null;
    }
}
