<?php

declare(strict_types=1);

/*
 * THE ERROR PAGES — Blade, rendered by Laravel's exception handler, never shipped to the
 * browser as a catalogue. In the visitor's language when the request was a hosted one or
 * the visitor has picked a language; English otherwise, like the console they belong to.
 * `:brand` is the deployment's configured product name.
 */
return [
    'layout' => [
        'default_title' => 'Error',
        'default_heading' => 'Something went wrong',
        'default_message' => 'An unexpected error occurred. Please try again.',
        'home' => 'Back to dashboard',
        'reload' => 'Reload',
        'share_trace' => 'Share this with support to help us trace what happened.',
        'copy_trace' => 'Copy trace ID',
        'trace_id' => 'Trace ID',
        'copied' => 'Copied',
    ],

    '403' => [
        'title' => 'Access denied',
        'message' => 'You don’t have permission to view this page. If you think this is a mistake, contact your administrator.',
    ],

    '404' => [
        'title' => 'Page not found',
        'message' => 'We couldn’t find the page you were looking for. It may have moved or no longer exists.',
    ],

    '419' => [
        'title' => 'Your session expired',
        'message' => 'For your security you were signed out after a period of inactivity. Reload the page to continue.',
    ],

    '429' => [
        'title' => 'Too many requests',
        'message' => 'You’ve made a lot of requests in a short time. Wait a moment, then try again.',
    ],

    '500' => [
        'title' => 'Something went wrong',
        'message' => 'An unexpected error occurred on our side. The team has been notified — reloading usually helps.',
    ],

    '503' => [
        'title' => 'Down for maintenance',
        'message' => ':brand is briefly unavailable while we carry out maintenance. Please try again in a moment.',
    ],
];
