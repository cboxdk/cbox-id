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
    /**
     * Password correct, but an organization this person belongs to mandates single
     * sign-on, so a local credential is not a way in — no session, and none obtainable
     * here.
     *
     * SEPARATE FROM Invalid ON PURPOSE, and it is a disclosure worth naming. The refusal
     * used to be reported as a wrong password, which is the most enumeration-resistant
     * answer available and also a dead end: the one population that reaches it is people
     * who typed the RIGHT password, and they were told to try again. Distinguishing it
     * tells an observer that a correct password was entered — the same thing {@see self::Mfa}
     * and {@see self::Otp} have always told them, since both advance to a second screen
     * that a wrong password never reaches. It reveals nothing a bystander could use here:
     * the door stays shut either way.
     */
    case SsoRequired = 'sso_required';
    /** Wrong password, unknown identity, or a suspended account — never authenticates. */
    case Invalid = 'invalid';
}
