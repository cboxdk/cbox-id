<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Enums;

/**
 * The handset family an enrolled device belongs to. Both reach the same transport —
 * FCM relays to APNs for iOS — but the per-platform envelope differs (priority
 * headers, collapse keys, interruption level), so the sender needs to know which.
 */
enum DevicePlatform: string
{
    case Ios = 'ios';
    case Android = 'android';

    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iOS',
            self::Android => 'Android',
        };
    }
}
