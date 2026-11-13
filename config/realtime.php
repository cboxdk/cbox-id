<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Live updates
|--------------------------------------------------------------------------
|
| The console and account pages reload their own data when a record behind them
| changes: a trusted device enrolled, a sign-in, a token revoked, a member added.
| What travels over the socket is an INVALIDATION SIGNAL — which kind of record, its
| id and what happened — never a value. The page re-reads through the same gates it
| was drawn through. See docs/operations/realtime.md.
|
| Off unless broadcasting is `reverb` with an application key: with
| BROADCAST_CONNECTION=null (the default) nothing is queued and no page connects.
|
*/

return [

    // A switch to turn the feature off while Reverb stays configured for something else.
    'enabled' => (bool) env('CBOX_ID_REALTIME_ENABLED', true),

    /*
     * Where a BROWSER connects. Empty host = the host the page was served from, which is
     * the shape behind a tunnel or proxy that routes `/app/{key}` on every console host to
     * Reverb: the socket is then same-origin and `connect-src 'self'` already admits it.
     * Locally, point it at `reverb:start` (REVERB_HOST=localhost, REVERB_PORT=8080,
     * REVERB_SCHEME=http).
     */
    'client' => [
        'host' => env('CBOX_ID_REALTIME_HOST', env('REVERB_HOST')),
        'port' => (int) env('CBOX_ID_REALTIME_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('CBOX_ID_REALTIME_SCHEME', env('REVERB_SCHEME', 'https')),
    ],

    // The queue publishes go on. Null = the connection's default queue.
    'queue' => env('CBOX_ID_REALTIME_QUEUE'),

    /*
     * A channel is published to only while somebody has opened it recently: a successful
     * channel authorization marks it watched for this long. Every page visit re-authorizes,
     * so a console left open is re-marked as it is used. Nobody watching = nothing queued,
     * which is the common case for a person's own channel.
     */
    'watch_seconds' => (int) env('CBOX_ID_REALTIME_WATCH_SECONDS', 6 * 3600),

    /*
     * At most one publish per channel and topic per window, plus one trailing publish for
     * whatever changed inside it — so a burst (a bulk import, a busy sign-in hour) costs a
     * couple of messages a second, not one per row, and the last change is never lost.
     */
    'throttle_seconds' => (int) env('CBOX_ID_REALTIME_THROTTLE_SECONDS', 1),

    // The most record references one message names; beyond it the page just reloads.
    'max_changes' => 20,

];
