<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * A user agent, as something a person can recognise.
 *
 * "Chrome on macOS" is a thing somebody can compare against what they are holding.
 * `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko)
 * Chrome/141.0.0.0 Safari/537.36` is not — and the whole value of a session list is that a
 * person can look down it and find the one they do not recognise.
 *
 * DELIBERATELY CRUDE, and deliberately not a dependency. Real user-agent parsing is a
 * losing arms race against a string every vendor lies in, and the failure mode here is
 * mild: an unrecognised agent falls back to the raw string, which is exactly what the
 * screen showed before. A library would be a supply-chain dependency, an update cadence
 * and a licence review for a cosmetic label.
 */
final class DeviceLabel
{
    /** Browsers, most specific first — Edge and Chrome both claim to be Safari. */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** Platforms, most specific first — Android claims Linux. */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function for(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            // Not "Unknown device": a session with no agent is almost always something
            // that is not a browser at all — a CLI, a script, an SDK — and saying so is
            // more useful than admitting we did not look.
            return 'A client that sent no browser name';
        }

        $browser = null;

        foreach (self::BROWSERS as $needle => $name) {
            if (str_contains($agent, $needle)) {
                $browser = $name;

                break;
            }
        }

        $platform = null;

        foreach (self::PLATFORMS as $needle => $name) {
            if (str_contains($agent, $needle)) {
                $platform = $name;

                break;
            }
        }

        if ($browser !== null && $platform !== null) {
            return $browser.' on '.$platform;
        }

        // One of the two, or neither. The raw string beats a confident guess: somebody
        // trying to recognise their own session is better served by the truth than by
        // "Unknown".
        return $browser ?? $platform ?? mb_strimwidth($agent, 0, 60, '…');
    }
}
