<?php

declare(strict_types=1);

namespace App\Platform\Actions;

/**
 * How much harm an action can do if the wrong caller runs it — the one judgement every
 * door reads: MCP marks a tool read-only or destructive from it, approval policies key on
 * it, and an agent's prompt shows it.
 */
enum Danger: string
{
    /** Reads only. */
    case Read = 'read';

    /** Changes something, and the change can be undone by another write. */
    case Write = 'write';

    /** Removes or revokes something; undoing it means recreating it. */
    case Destructive = 'destructive';

    /** Mints or rotates credentials, deletes environments, changes how people sign in. */
    case Critical = 'critical';

    public function writes(): bool
    {
        return $this !== self::Read;
    }

    public function destructive(): bool
    {
        return $this === self::Destructive || $this === self::Critical;
    }
}
