<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Enums\ApprovalKind;

/**
 * What the next action approval filed in this request is for, said by the code filing it.
 *
 * The browser's "confirm with your phone" flow files an ordinary action approval, and
 * without this the phone would be told "Approve an action" for a sign-in. The flow states
 * the kind immediately before it files; the push decorator {@see pull()}s it as the request
 * is filed, so it can never colour a later one. Scoped: one per request or job.
 */
final class ApprovalKindHint
{
    private ?ApprovalKind $kind = null;

    public function expect(ApprovalKind $kind): void
    {
        $this->kind = $kind;
    }

    /** The stated kind, once; cleared as it is read. */
    public function pull(): ?ApprovalKind
    {
        $kind = $this->kind;
        $this->kind = null;

        return $kind;
    }
}
