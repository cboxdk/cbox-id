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
 *
 * `cookieless` says whether this attempt came through a door that CANNOT carry the cookie
 * (the cross-origin Frontend API). Only there may the fingerprint stand in for it: every
 * header it is made of is sent by the client and seen by every site the person visits, so
 * on a door where the cookie could have come back and did not, matching headers prove
 * nothing.
 */
final readonly class DeviceIdentity
{
    public function __construct(
        public string $deviceHash,
        public bool $returning,
        public string $fingerprintHash,
        public bool $cookieless = false,
    ) {}
}
