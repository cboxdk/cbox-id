<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Enums;

/**
 * Operator/user INTENT for a device, kept separate from its delivery health (the
 * circuit-breaker columns). A device can be Active and failing, or Retired and
 * perfectly reachable; conflating the two would let a transient outage look like a
 * revocation.
 */
enum DeviceStatus: string
{
    /** Enrolled and eligible to receive pushes. */
    case Active = 'active';

    /**
     * No longer eligible. Either the user removed it, or the transport told us the
     * token is permanently dead. The row is kept — the audit trail refers to it — but
     * the push token is cleared, because a retired device must not retain a live
     * capability.
     */
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Retired => 'Retired',
        };
    }
}
