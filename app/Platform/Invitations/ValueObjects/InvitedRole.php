<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

/**
 * One app or custom role an invitation will grant, as the invitee is told about it.
 *
 * `$appName` is the app the role belongs to, or null for a custom role — one an
 * administrator made in the console, which reaches every app. The same split the People
 * page's role picker groups by, so the invitee reads the words the inviter ticked.
 */
final readonly class InvitedRole
{
    public function __construct(
        public string $name,
        public ?string $appName = null,
    ) {}
}
