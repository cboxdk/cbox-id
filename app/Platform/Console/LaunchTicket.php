<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The administrator's app-host session, carried across to an environment's own host
 * WITHOUT naming it.
 *
 * The support and impersonation launch links leave the console for a host a customer
 * controls, whose access logs keep every query string. So the link carries an opaque,
 * single-use ticket, and the session row id it stands for stays here, server side, for as
 * long as the link lives.
 */
final class LaunchTicket
{
    private const PREFIX = 'cbox.console_launch_ticket.';

    /** A ticket for $consoleSessionId, good for $seconds and one redemption. */
    public static function issue(string $consoleSessionId, int $seconds): string
    {
        $ticket = Str::random(48);

        Cache::put(self::PREFIX.$ticket, $consoleSessionId, $seconds);

        return $ticket;
    }

    /** The console session a ticket stands for, or null; spent either way. */
    public static function redeem(mixed $ticket): ?string
    {
        if (! is_string($ticket) || $ticket === '') {
            return null;
        }

        $session = Cache::pull(self::PREFIX.$ticket);

        return is_string($session) && $session !== '' ? $session : null;
    }
}
