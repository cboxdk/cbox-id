<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Whether self-service signup is available, per `cbox-id.signup.mode`. Admin
 * invitations and operator provisioning are separate and never gated here.
 */
final class SignupPolicy
{
    public function mode(): string
    {
        $mode = config('cbox-id.signup.mode', 'open');

        return in_array($mode, ['open', 'invite_only', 'closed'], true) ? $mode : 'open';
    }

    public function isOpen(): bool
    {
        return $this->mode() === 'open';
    }

    public function closedMessage(): string
    {
        return $this->mode() === 'invite_only'
            ? 'Signups are invite-only. Ask an administrator for an invitation.'
            : 'Signups are currently closed.';
    }
}
