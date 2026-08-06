<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\SubjectCredentialGate;

/**
 * What {@see SubjectCredentialGate::admits()} decided about a VERIFIED password.
 *
 * A bool used to be enough because both refusals ended the same way — the door said
 * "those credentials do not match a workspace" and there was nothing else to say. There
 * is now: an SSO mandate is a refusal the person can act on, and an expired hand-off
 * credential is not. An enum rather than two bools so the caller `match`es exhaustively
 * and a third rule added here cannot be silently folded into whichever branch happens to
 * be the default.
 */
enum CredentialVerdict: string
{
    /** Nothing standing in the way — carry on with the sign-in. */
    case Admitted = 'admitted';

    /**
     * An organization this member belongs to mandates single sign-on. The password is
     * right and will stay refused; the member needs their identity provider.
     */
    case SsoRequired = 'sso_required';

    /**
     * Refused for a reason the person cannot act on and must not be told about — today,
     * an administratively-issued temporary password whose deadline has passed.
     */
    case Refused = 'refused';
}
