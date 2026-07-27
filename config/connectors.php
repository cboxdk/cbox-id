<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the Connectors console surface. The catalog and connections
     * pages, their routes and the dashboard card are all gated on this via the
     * console-kit feature registry. Installed and true ⇒ the area shows; set false
     * to hide the plugin's UI without uninstalling it.
     */
    'enabled' => (bool) env('ID_CONNECTORS_ENABLED', true),

    /*
     * The public WebhookRegistry contract lists endpoints only by event type
     * (matching()), so recovering an organization's active webhook endpoints means
     * unioning matches over a set of candidate event types. This is that set —
     * widen it to the event types your platform actually emits so no subscribed
     * endpoint is missed. (Paused endpoints are never returned by the contract and
     * so are never shown, regardless of this list.)
     */
    'webhook_event_types' => [
        'auth.login',
        'auth.user',
        'auth.mfa_enrolled',
        'organization.member_added',
        'organization.member_removed',
    ],
];
