<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\ValueObjects;

use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\NotificationKind;

/**
 * One addressed, ready-to-send push: the plaintext token, who it is for, and what it
 * says. Built at the last possible moment inside the delivery attempt so the unsealed
 * token lives for as short a time as possible and never reaches a queue payload.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $token,
        public DevicePlatform $platform,
        public NotificationKind $kind,
        public PushPayload $payload,
    ) {}
}
