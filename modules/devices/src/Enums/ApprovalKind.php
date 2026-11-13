<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Enums;

use Cbox\Id\Devices\Support\ApprovalKindHint;

/**
 * What a person is being asked to approve on their phone — what the push says and what the
 * approvals API reports as `kind`.
 *
 * Told, never inferred: the browser flow states sign-in or step-up before it files the
 * request ({@see ApprovalKindHint}); any other action approval
 * is an `action`, and every CIBA request is an `authentication`. Nothing parses the
 * binding message to find out.
 */
enum ApprovalKind: string
{
    /** "Confirm with your phone" while signing in, in a browser. */
    case SignIn = 'sign_in';

    /** "Confirm with your phone" to re-confirm a signed-in console session. */
    case StepUp = 'step_up';

    /** One held action, filed by the platform's own step-up client. */
    case Action = 'action';

    /** An app's backchannel (CIBA) request. */
    case Authentication = 'authentication';

    /** Whether a browser on a web host asked — the only kinds that report a host. */
    public function fromBrowser(): bool
    {
        return $this === self::SignIn || $this === self::StepUp;
    }
}
