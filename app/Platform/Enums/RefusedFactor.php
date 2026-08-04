<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\SsoRefusal;

/**
 * What somebody had just proved when an SSO mandate refused them a session.
 *
 * The mandate screen is the same screen for all of them — one organization, one link to
 * its identity provider — but the first sentence cannot be. "Your password is correct" is
 * what the password door said, and it was the only door that could say it; told to
 * somebody who had just tapped a passkey or clicked an emailed link, it describes
 * something they did not do, which reads as a bug at the exact moment they most need to
 * believe the screen.
 *
 * So the factor travels with the refusal ({@see SsoRefusal}) and picks the sentence. The
 * cases are DOORS rather than credential kinds — an invitation and a password reset are
 * both "an emailed token that then sets a password", and they still need different words,
 * because one of them is somebody's first minute here and the other is not.
 */
enum RefusedFactor: string
{
    case Password = 'password';

    case MagicLink = 'magic_link';

    case Passkey = 'passkey';

    case Social = 'social';

    case Invitation = 'invitation';

    case PasswordReset = 'password_reset';

    /**
     * What to tell the person, in full.
     *
     * Every one of them opens by confirming that what they did WORKED, because it did —
     * they are not holding a wrong credential and telling them so is the failure this
     * whole change exists to undo. The refusal is the organization's decision, said as
     * the organization's decision, and each sentence ends by pointing at the way in.
     */
    public function sentence(): string
    {
        return match ($this) {
            self::Password => 'Your password is correct — it is just not a way in here any more. Sign in through your organization\'s identity provider instead.',
            self::MagicLink => 'That sign-in link worked, and it has now been used up. Emailed links are not a way in here any more — sign in through your organization\'s identity provider instead.',
            self::Passkey => 'Your passkey worked. It is just not a way in here any more — sign in through your organization\'s identity provider instead.',
            self::Social => 'That sign-in worked, but it is not the identity provider your organization has chosen. Sign in through theirs instead.',
            self::Invitation => 'Your invitation is accepted and you are a member now. Sign in through your organization\'s identity provider to get started.',
            self::PasswordReset => 'Your new password is saved, but a password is not a way in here any more. Sign in through your organization\'s identity provider instead.',
        };
    }
}
