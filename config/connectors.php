<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the Connectors console surface. The catalog and connections
     * pages, their routes and the dashboard card are all gated on this via the
     * console-kit feature registry. Installed and true ⇒ the area shows; set false
     * to hide the plugin's UI without uninstalling it.
     *
     * There used to be a `webhook_event_types` list beside this: the webhook registry's
     * public contract could only find endpoints by event type, so an endpoint subscribed
     * to anything not on the list was missing from Connections. The list now reads the
     * endpoints themselves and the setting is gone; a published copy that still has it is
     * ignored.
     */
    'enabled' => (bool) env('CBOX_ID_CONNECTORS_ENABLED', true),
];
