<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Controllers\OAuthConsentController;
use App\Http\Controllers\Sso\SamlIdpSsoController;
use App\Http\Middleware\Authenticate;
use App\Platform\Enums\SignInHold;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Identity\Contracts\PasswordExpiry;

/**
 * What a signed-in person still owes before a session may be USED: one answer for every
 * door that hands out something on the strength of a session.
 *
 * The console ({@see Authenticate}), the authorize endpoint ({@see OAuthConsentController})
 * and the SAML IdP ({@see SamlIdpSsoController}) each mint or show something for "whoever
 * holds this session". They asked these questions separately, and the SAML IdP did not ask
 * them at all: a password-only session held on /mfa by the console was still given a signed
 * assertion for every service provider.
 *
 * VERIFICATION BEFORE ENROLMENT, when there is a factor to verify. The MFA mandate's
 * "enrol a stronger factor" ({@see MfaMandate::requiresEnrolment()}) is also true for an
 * organization owner or admin whose only factor is SMS. Reading it as "has nothing to
 * verify" is what let a magic link (the mailbox alone) skip that person's SMS code: the
 * enrolment page exempts the very screens where a new authenticator is added. The person
 * proves the factor they have first, as the password door already makes them, and is
 * asked for the stronger one afterwards.
 */
final class SignInHolds
{
    /**
     * Whether a session with these methods must prove a second factor before it is used.
     *
     * @param  list<mixed>  $amr
     */
    public function mustVerify(string $subjectId, array $amr): bool
    {
        $amr = array_values(array_filter($amr, is_string(...)));

        if (! app(MfaVerification::class)->requiresVerification($subjectId, $amr)) {
            return false;
        }

        // A factor the person can answer with is asked for, whatever else the mandate
        // wants. Only somebody with nothing to answer is sent to enrol instead.
        return app(PlatformAuth::class)->hasSecondFactor($subjectId)
            || ! app(MfaMandate::class)->requiresEnrolment($subjectId);
    }

    public function mustChangePassword(string $subjectId): bool
    {
        return app(AdminPasswords::class)->requiresChange($subjectId)
            || app(PasswordExpiry::class)->hasExpired($subjectId);
    }

    public function mustEnrol(string $subjectId): bool
    {
        return app(MfaMandate::class)->requiresEnrolment($subjectId);
    }

    /**
     * The first thing this session owes, or null when it may be used.
     *
     * @param  list<mixed>  $amr
     */
    public function pending(string $subjectId, array $amr): ?SignInHold
    {
        return match (true) {
            $this->mustChangePassword($subjectId) => SignInHold::PasswordChange,
            $this->mustVerify($subjectId, $amr) => SignInHold::Verification,
            $this->mustEnrol($subjectId) => SignInHold::Enrolment,
            default => null,
        };
    }
}
