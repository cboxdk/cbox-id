<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Contracts;

use Cbox\Id\Devices\Transports\NullPushTransport;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushResult;

/**
 * Sends one push to one device.
 *
 * Implementations MUST NOT throw for an ordinary delivery failure — a dead handset, a
 * rejected token, a 503 from the provider are all outcomes, and they come back as a
 * {@see PushResult}. Throwing is reserved for genuine programming or configuration
 * faults, and the dispatcher treats a throw as a transient failure so that a broken
 * transport degrades into retries rather than taking a login down with it.
 *
 * The default binding is the {@see NullPushTransport},
 * which sends nothing. That is deliberate: push must be opt-in, and an unconfigured
 * deployment should be inert rather than half-working.
 */
interface PushTransport
{
    public function send(PushMessage $message): PushResult;
}
