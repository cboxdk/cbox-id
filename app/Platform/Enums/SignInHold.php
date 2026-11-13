<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\SignInHolds;

/**
 * What a session still owes before it may be used ({@see SignInHolds}), named by the route
 * that settles it.
 */
enum SignInHold: string
{
    case PasswordChange = 'password.change';
    case Verification = 'mfa';
    case Enrolment = 'account';

    /** Why the session is held, for a client that cannot be shown the page. */
    public function reason(string $doing): string
    {
        return match ($this) {
            self::PasswordChange => "The user must change their password before {$doing}.",
            self::Verification => "The user must verify a second factor before {$doing}.",
            self::Enrolment => "The user must enrol a second factor before {$doing}.",
        };
    }
}
