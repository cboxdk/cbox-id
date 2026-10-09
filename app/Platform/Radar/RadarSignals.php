<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\IpIntelligence\IpIntelligence;
use App\Platform\Radar\IpIntelligence\IpProfile;
use Cbox\Risk\Contracts\DisposableDomains;
use Cbox\Risk\Contracts\TorExitNodes;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Illuminate\Http\Request;
use Throwable;

/**
 * Gathers the facts about one attempt: where the address is and whose network, which device,
 * how fast this IP and this address are going, how far the account has travelled since its
 * last successful sign-in, and whether the mail domain is a throwaway one.
 *
 * Every source FAILS OPEN to "unknown" — a lookup that throws, a cache that is down, a table
 * that is not migrated — because a fact Radar cannot establish is a rule that does not fire,
 * never a sign-in that fails.
 */
final readonly class RadarSignals
{
    /** The counters' windows, in seconds. */
    public const int MINUTE = 60;

    public const int TEN_MINUTES = 600;

    public const int HOUR = 3600;

    public function __construct(
        private IpIntelligence $intelligence,
        private TorExitNodes $tor,
        private DisposableDomains $disposable,
        private RadarDevices $devices,
        private RadarVelocity $velocity,
        private RadarPseudonyms $pseudonyms,
    ) {}

    /**
     * @return array{facts: RadarFacts, profile: IpProfile|null, device: DeviceIdentity|null}
     */
    public function collect(
        Request $request,
        RadarFlow $flow,
        RadarMethod $method,
        ?string $email,
        RiskAssessment $assessment,
        RadarPolicySnapshot $policy,
    ): array {
        $ip = $request->ip();
        $ip = is_string($ip) ? RadarAddresses::canonicalIp($ip) : null;
        $email = is_string($email) && trim($email) !== '' ? RadarPseudonyms::canonicalEmail($email) : null;
        $domain = $email !== null && str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : null;
        $domain = $domain === '' ? null : $domain;

        $profile = $ip === null ? null : $this->safely(fn (): ?IpProfile => $this->intelligence->lookup($ip));
        $tor = $ip !== null && $this->safely(fn (): bool => $this->tor->contains($ip)) === true;
        $device = $this->safely(fn (): DeviceIdentity => $this->devices->identify($request));

        $values = [
            RadarField::Ip->value => $ip,
            RadarField::Email->value => $email,
            RadarField::EmailDomain->value => $domain,
            RadarField::UserAgent->value => $request->userAgent(),
            RadarField::Method->value => $method->value,
            RadarField::RiskScore->value => round($assessment->score, 2),
            RadarField::Country->value => $profile?->country,
            RadarField::Asn->value => $profile?->asn,
            RadarField::AsOrganization->value => $profile?->asOrganization,
            RadarField::IsHosting->value => $profile?->hosting,
            RadarField::IsVpn->value => $profile?->vpn,
            RadarField::IsProxy->value => $profile?->proxy,
            RadarField::IsTor->value => $tor || ($profile->tor ?? false) ? true : ($profile === null ? null : false),
            RadarField::DisposableEmail->value => $domain === null ? null : $this->safely(fn (): bool => $this->disposable->contains($domain)),
        ];

        $values = [...$values, ...($this->safely(fn () => $this->velocity($ip, $email, $device)) ?? [])];
        $values = [...$values, ...($this->safely(fn () => $this->history($flow, $email, $device, $profile, $policy)) ?? [])];

        return [
            'facts' => new RadarFacts(
                $flow,
                $method,
                $values,
                $device !== null && $device->returning ? $device->deviceHash : null,
                $assessment->outcome,
            ),
            'profile' => $profile,
            'device' => $device,
        ];
    }

    /**
     * A sign-in failed (a wrong password, an unknown address): count it against the IP and
     * the address, for the "repeated failures" rule and the failure facts.
     */
    public function recordFailure(Request $request, ?string $email): void
    {
        $this->safely(function () use ($request, $email): void {
            $ip = $request->ip();

            if (is_string($ip)) {
                $this->velocity->hit('ip_failures', $ip, self::HOUR);
            }

            if (is_string($email) && trim($email) !== '') {
                $this->velocity->hit('email_failures', RadarPseudonyms::canonicalEmail($email), self::HOUR);
            }
        });
    }

    /**
     * Count this attempt, then read the counters it moved.
     *
     * @return array<string, int>
     */
    private function velocity(?string $ip, ?string $email, ?DeviceIdentity $device): array
    {
        $values = [];

        if ($ip !== null) {
            $this->velocity->hit('ip_attempts', $ip, self::MINUTE);
            $this->velocity->hit('ip_attempts', $ip, self::HOUR);

            if ($email !== null) {
                $this->velocity->remember('ip_emails', $ip, $this->pseudonyms->email($email), self::TEN_MINUTES);
            }

            $values[RadarField::IpAttempts1m->value] = $this->velocity->count('ip_attempts', $ip, self::MINUTE);
            $values[RadarField::IpAttempts1h->value] = $this->velocity->count('ip_attempts', $ip, self::HOUR);
            $values[RadarField::IpDistinctEmails10m->value] = $this->velocity->distinct('ip_emails', $ip, self::TEN_MINUTES);
            $values[RadarField::IpFailures1h->value] = $this->velocity->count('ip_failures', $ip, self::HOUR);
        }

        if ($email !== null) {
            $this->velocity->hit('email_attempts', $email, self::HOUR);
            $values[RadarField::EmailAttempts1h->value] = $this->velocity->count('email_attempts', $email, self::HOUR);
            $values[RadarField::EmailFailures1h->value] = $this->velocity->count('email_failures', $email, self::HOUR);
        }

        // Only a RETURNING cookie names a device. A client that drops cookies gets a fresh
        // identifier on every attempt, and counting those would count nothing.
        if ($device !== null && $device->returning) {
            $this->velocity->hit('device_attempts', $device->deviceHash, self::HOUR);
            $values[RadarField::DeviceAttempts1h->value] = $this->velocity->count('device_attempts', $device->deviceHash, self::HOUR);
        }

        return $values;
    }

    /**
     * What the account's own history says: is this a device it has signed in from, and how
     * far has it come since the last sign-in that could be placed.
     *
     * Sign-in only, and only with an address to key on — a passkey or magic-link attempt is
     * assessed before anybody is known, so these facts are unknown there.
     *
     * @return array<string, bool|int>
     */
    private function history(RadarFlow $flow, ?string $email, ?DeviceIdentity $device, ?IpProfile $profile, RadarPolicySnapshot $policy): array
    {
        if ($flow !== RadarFlow::SignIn || $email === null || $device === null) {
            return [];
        }

        $history = $this->devices->history($this->pseudonyms->subject($email));

        // No history is a first sign-in, not a new device: there is nothing it is new TO.
        if ($history->isEmpty()) {
            return [];
        }

        $values = [RadarField::NewDevice->value => ! $this->devices->knows($history, $device)];
        $last = $this->devices->lastLocated($history);

        if ($last !== null && $profile !== null && $profile->hasLocation()) {
            [$point, $at] = $last;
            $minKm = config('cbox-id.radar.impossible_travel_min_km', 300);
            $speed = RadarTravel::speedKmh(
                $point,
                $at,
                new GeoPoint((float) $profile->latitude, (float) $profile->longitude),
                now()->getTimestamp(),
                is_numeric($minKm) ? (float) $minKm : 300.0,
            );

            $values[RadarField::TravelKmh->value] = (int) round($speed);
            $values[RadarField::ImpossibleTravel->value] = $speed >= $policy->threshold(RadarBuiltin::ImpossibleTravel);
        }

        return $values;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T|null  $fallback
     * @return T|null
     */
    private function safely(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
