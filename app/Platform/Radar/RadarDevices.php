<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarDevice;
use App\Platform\Radar\IpIntelligence\IpProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * Recognising a browser, privately.
 *
 * FIRST PARTY ONLY. The device cookie is set by this host, for this host, and holds a random
 * identifier — 128 bits from the CSPRNG, nothing derived from the person or the machine. It
 * is HttpOnly, SameSite=Lax and Secure wherever the session cookie is, so page script cannot
 * read it and no other site is sent it. The server stores only a keyed pseudonym of it, under
 * the environment. There is no third-party script, no canvas or font probing, no storage
 * beyond the cookie.
 *
 * The FINGERPRINT is the weaker fallback for clients that keep no cookies (the cross-origin
 * Frontend API, a private window): the user agent with every version reduced to its major
 * number, the `Sec-CH-UA` client hints the browser sends anyway, and the first language it
 * asks for — hashed, keyed, never stored raw. It is coarse on purpose: two colleagues on the
 * same browser and OS look alike, which is the right failure for a "have we seen this
 * before?" question.
 *
 * Devices are remembered only after a sign-in SUCCEEDS ({@see self::remember()}), so nobody
 * can add a device to an account by typing its address.
 */
final readonly class RadarDevices
{
    /** Four hundred days — the longest a browser will keep a cookie. */
    private const int COOKIE_MINUTES = 400 * 24 * 60;

    /** Where this request's device identifier is kept, so assessment and success agree. */
    private const string ATTRIBUTE = 'radar.device_id';

    public function __construct(private RadarPseudonyms $pseudonyms) {}

    public function identify(Request $request): DeviceIdentity
    {
        $name = self::cookieName();
        $issued = $request->attributes->get(self::ATTRIBUTE);
        $sent = $request->cookie($name);
        $returning = is_string($sent) && preg_match('/^[a-f0-9]{32}$/', $sent) === 1;

        if ($returning) {
            $id = $sent;
        } elseif (is_string($issued)) {
            $id = $issued;
        } else {
            $id = bin2hex(random_bytes(16));
            $request->attributes->set(self::ATTRIBUTE, $id);

            Cookie::queue(Cookie::make(
                $name,
                $id,
                self::COOKIE_MINUTES,
                '/',
                null,
                (bool) (config('session.secure') ?? $request->isSecure()),
                true,
                false,
                'lax',
            ));
        }

        return new DeviceIdentity(
            $this->pseudonyms->device($id),
            $returning,
            $this->pseudonyms->fingerprint(self::fingerprintMaterial($request)),
        );
    }

    /**
     * The devices this account has signed in from, most recent first.
     *
     * @return Collection<int, RadarDevice>
     */
    public function history(string $subjectHash): Collection
    {
        return RadarDevice::query()
            ->where('subject_hash', $subjectHash)
            ->orderByDesc('last_seen_at')
            ->limit(RadarDevice::MAX_PER_SUBJECT)
            ->get();
    }

    /**
     * @param  Collection<int, RadarDevice>  $history
     */
    public function knows(Collection $history, DeviceIdentity $device): bool
    {
        foreach ($history as $known) {
            if (($device->returning && $known->device_hash === $device->deviceHash) || $known->fingerprint_hash === $device->fingerprintHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last successful sign-in that could be placed on a map, as a point and a time.
     *
     * @param  Collection<int, RadarDevice>  $history
     * @return array{0: GeoPoint, 1: int}|null
     */
    public function lastLocated(Collection $history): ?array
    {
        foreach ($history as $known) {
            if ($known->latitude !== null && $known->longitude !== null) {
                return [new GeoPoint((float) $known->latitude, (float) $known->longitude), $known->last_seen_at->getTimestamp()];
            }
        }

        return null;
    }

    /**
     * A sign-in SUCCEEDED from this device: remember it, and where it was.
     */
    public function remember(string $subjectHash, DeviceIdentity $device, ?IpProfile $profile): void
    {
        $now = Carbon::now();

        $known = RadarDevice::query()
            ->where('subject_hash', $subjectHash)
            ->where(static function ($query) use ($device): void {
                $query->where('device_hash', $device->deviceHash)
                    ->orWhere(static function ($query) use ($device): void {
                        $query->whereNull('device_hash')->where('fingerprint_hash', $device->fingerprintHash);
                    });
            })
            ->first();

        $row = $known ?? new RadarDevice;
        $row->forceFill([
            'subject_hash' => $subjectHash,
            'device_hash' => $device->deviceHash,
            'fingerprint_hash' => $device->fingerprintHash,
            'first_seen_at' => $known->first_seen_at ?? $now,
            'last_seen_at' => $now,
        ]);

        // A sign-in that could not be placed keeps the last point that could: unknown is not
        // "nowhere", and wiping it would hide the next impossible hop.
        if ($profile !== null && $profile->hasLocation()) {
            $row->forceFill([
                'country' => $profile->country,
                'latitude' => round((float) $profile->latitude, 1),
                'longitude' => round((float) $profile->longitude, 1),
            ]);
        } elseif ($profile?->country !== null) {
            $row->forceFill(['country' => $profile->country]);
        }

        $row->save();

        $this->trim($subjectHash);
    }

    public static function cookieName(): string
    {
        $name = config('cbox-id.radar.device_cookie', 'cbox_device');

        return is_string($name) && $name !== '' ? $name : 'cbox_device';
    }

    /**
     * The normalised material the fingerprint is a pseudonym of.
     */
    public static function fingerprintMaterial(Request $request): string
    {
        $reduce = static fn (?string $value): string => strtolower(trim((string) preg_replace('/(\d+)(?:\.\d+)+/', '$1', (string) $value)));
        $language = strtolower(trim(explode(',', (string) $request->header('Accept-Language'))[0]));

        return implode('|', [
            $reduce($request->userAgent()),
            $reduce($request->header('Sec-CH-UA')),
            strtolower(trim((string) $request->header('Sec-CH-UA-Platform'))),
            strtolower(trim((string) $request->header('Sec-CH-UA-Mobile'))),
            explode(';', $language)[0],
        ]);
    }

    private function trim(string $subjectHash): void
    {
        $stale = RadarDevice::query()
            ->where('subject_hash', $subjectHash)
            ->orderByDesc('last_seen_at')
            ->offset(RadarDevice::MAX_PER_SUBJECT)
            ->limit(1000)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            RadarDevice::query()->whereIn('id', $stale)->delete();
        }
    }
}
