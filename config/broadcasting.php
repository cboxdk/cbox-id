<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported here: "reverb", "log", "null".
    |
    | Cbox ID broadcasts INVALIDATION SIGNALS only (what kind of record changed, its
    | id and the action, never a value), so a page can reload its own data through the
    | gates it already passes. "null" — the default — turns realtime off entirely: no
    | broadcast is queued and pages do not try to connect. See docs/operations/realtime.md.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // Where the web and worker pods PUBLISH: in a cluster, the Reverb Service over the
            // pod network (REVERB_PUBLISH_*); the browser's address is the REVERB_* below.
            'options' => [
                'host' => env('REVERB_PUBLISH_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_PUBLISH_PORT', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_PUBLISH_SCHEME', env('REVERB_SCHEME', 'https')),
                'useTLS' => env('REVERB_PUBLISH_SCHEME', env('REVERB_SCHEME', 'https')) === 'https',
            ],
            // A publish is a queued job; a Reverb that does not answer must fail it fast, not
            // hold a worker. The signal is best-effort: the page is still right on reload.
            'client_options' => [
                'timeout' => (float) env('REVERB_PUBLISH_TIMEOUT', 5),
                'connect_timeout' => (float) env('REVERB_PUBLISH_CONNECT_TIMEOUT', 2),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
