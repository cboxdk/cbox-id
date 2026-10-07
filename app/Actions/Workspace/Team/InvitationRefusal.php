<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Platform\Actions\ActionRefused;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;

/**
 * A team invitation's refusal, as the workspace plane has always answered it: a person
 * already on the team is `email_taken` (422), an invitation that is not pending here is
 * `not_found`, a re-send inside the minute is `too_soon` (429), and a mail server that
 * refused is `mail_failed` (503). A helper, not an action.
 */
final class InvitationRefusal
{
    public static function of(InvitationRefused $refused): ActionRefused
    {
        [$error, $status] = match ($refused->reason) {
            InvitationRefusalReason::AlreadyMember => ['email_taken', 422],
            InvitationRefusalReason::NotPending => ['not_found', 404],
            InvitationRefusalReason::TooSoon => ['too_soon', 429],
            InvitationRefusalReason::MailFailed => ['mail_failed', 503],
            default => ['validation_failed', 422],
        };

        return new ActionRefused($error, $refused->getMessage(), $status, 'email');
    }
}
