<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\MemberEmailVerification;

/**
 * What {@see MemberEmailVerification::resend()} decided, expressed as what the member
 * should be TOLD rather than as what was found — which is the whole point of the type.
 *
 * There are deliberately only two cases, and neither of them is "already verified".
 * Whether an address is confirmed is exactly the fact a resend control must not leak, so
 * a member whose address is already verified (and whose environment is nonetheless still
 * missing — a suspended account, a plan at its limit) is answered with {@see Sent}, the
 * same string, indistinguishable from a genuine send. The one state that IS reported
 * separately, {@see AlreadyProvisioned}, is not a secret from the person asking: it means
 * their environment exists, and it is listed on the very page the button sits on.
 */
enum VerificationResendOutcome: string
{
    /**
     * A fresh link went out — or the address was already confirmed and nothing was sent.
     * The caller cannot tell which, and neither can the member.
     */
    case Sent = 'sent';

    /** The environment is already standing; there is nothing left to confirm. */
    case AlreadyProvisioned = 'already_provisioned';

    public function message(string $email): string
    {
        return match ($this) {
            self::Sent => __('console.a_new_confirmation_link_is_on_its_way_to_33cb7b3b').$email.__('console.it_is_valid_for_24_hours_and_replaces_any_ea_07d996d4'),
            self::AlreadyProvisioned => __('console.nothing_to_confirm_your_environment_is_alrea_21ddd184'),
        };
    }
}
