<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * The outcome of a credential check across the three sign-in bridges (subject,
 * account member, operator). A closed set that drives the login flow — modelled as
 * an enum, not a magic string, so consumers `match` exhaustively.
 */
enum AttemptOutcome: string
{
    /** Password correct, no second factor — a full session was established. */
    case Ok = 'ok';
    /** Password correct, a confirmed TOTP is required — an MFA challenge is pending. */
    case Mfa = 'mfa';
    /** Password correct but the sign-in is risky — an emailed one-time code is pending. */
    case Otp = 'otp';
    /** Wrong password, unknown identity, or a suspended account — never authenticates. */
    case Invalid = 'invalid';
}
