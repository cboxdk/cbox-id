<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

/**
 * How somebody on the link step proved the existing account is theirs.
 *
 * Recorded on the `user.identity_linked` audit entry, because "linked by a password" and
 * "linked by a code mailed to the address" are different statements about what an
 * attacker would have needed — and somebody reading the trail after a takeover asks
 * exactly that.
 */
enum LinkProof: string
{
    /** The account's authenticator app. */
    case Totp = 'totp';

    /** One of the account's single-use recovery codes, in place of the authenticator. */
    case RecoveryCode = 'recovery_code';

    /** A code texted to the account's confirmed phone number. */
    case Sms = 'sms';

    /** The account's password. */
    case Password = 'password';

    /** A one-time code mailed to the account's own address. */
    case EmailCode = 'email_code';

    /** Connected from My account, by somebody already signed in and freshly stepped up. */
    case SignedIn = 'signed_in';
}
