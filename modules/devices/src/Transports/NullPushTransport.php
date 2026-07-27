<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Transports;

use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushResult;

/**
 * The default transport: sends nothing, reports nothing sent.
 *
 * This is what a deployment without FCM credentials runs, and what the test suite runs.
 * The dispatcher still writes notification rows and settles them as Skipped, so the
 * console's delivery history is populated and the fan-out logic is exercised — the
 * push simply never leaves the building.
 *
 * It reports NOT CONFIGURED rather than a failure, and the distinction is load-bearing
 * in both directions. A transient result would park every notification for retry
 * against a transport that will never succeed, filling the sweep with work that cannot
 * complete. A permanent result would be read as "this token is dead" and retire every
 * handset in the estate the first time anyone pushed without credentials set.
 */
final class NullPushTransport implements PushTransport
{
    public function send(PushMessage $message): PushResult
    {
        return PushResult::notConfigured();
    }
}
