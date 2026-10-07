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
        // In the visitor's language: the sentence is only ever read on the sign-in page.
        // The case values are the catalogue's keys, `auth.login.mandate.reasons.*`.
        return __('auth.login.mandate.reasons.'.$this->value);
    }
}
