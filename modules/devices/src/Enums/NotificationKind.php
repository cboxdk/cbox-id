<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Enums;

/**
 * What a push is for. This drives urgency, not just labelling: an Approval is on the
 * CIBA critical path against a 300-second deadline and is sent synchronously, while a
 * SecurityAlert rides the ordinary once-a-minute event relay.
 */
enum NotificationKind: string
{
    /** A pending CIBA backchannel request awaiting the user's decision. */
    case Approval = 'approval';

    /** Something happened to the account the user should know about. */
    case SecurityAlert = 'security_alert';

    public function label(): string
    {
        return match ($this) {
            self::Approval => 'Approval request',
            self::SecurityAlert => 'Security alert',
        };
    }

    /**
     * Whether a late delivery is worse than none. Approvals expire against the CIBA
     * TTL; an alert is still worth reading an hour later.
     */
    public function isDeadlined(): bool
    {
        return $this === self::Approval;
    }
}
