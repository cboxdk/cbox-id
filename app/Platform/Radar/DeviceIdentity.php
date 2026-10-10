<?php

declare(strict_types=1);

namespace App\Platform\Radar;

/**
 * The browser an attempt came from, as Radar recognises it.
 *
 * `deviceHash` is the pseudonym of the device cookie's identifier — of the one the browser
 * sent back, or of the one just issued to it. `returning` says which: only a returning cookie
 * identifies a device, because a client that discards cookies gets a fresh identifier on
 * every attempt and would otherwise look like a thousand new devices. `fingerprintHash` is
 * the pseudonym of the normalised user agent and client hints — the fallback for a client
 * that does not keep cookies.
 */
final readonly class DeviceIdentity
{
    public function __construct(
        public string $deviceHash,
        public bool $returning,
        public string $fingerprintHash,
    ) {}
}
