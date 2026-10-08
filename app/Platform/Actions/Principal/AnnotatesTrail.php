<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionTrail;

/**
 * A principal with something to add to every audit entry its actions cause — beyond who it
 * is ({@see Principal::auditActor()}) and which door it came through.
 *
 * The Admin Portal's session is the one that needs it: the person is nobody this platform
 * knows, so the entries name the link that let them in and whoever minted it. Said once by
 * the principal and written by {@see ActionRunner} through {@see ActionTrail}, so neither the
 * actions nor the framework services they call have to know a portal exists.
 */
interface AnnotatesTrail
{
    /**
     * @return array<string, string>
     */
    public function trailContext(): array;
}
