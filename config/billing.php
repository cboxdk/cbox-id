<?php

declare(strict_types=1);

return [

    /*
     * Whether this DEPLOYMENT bills anybody.
     *
     * Off: no `/billing` route, no rail entry, no page. Not "hidden" — absent, which is
     * the difference between a feature that is optional and a feature that is merely
     * permissioned. A self-hosted install with no plans and no invoices has nothing to
     * show here and should not carry the surface.
     *
     * ON BY DEFAULT, because the hosted platform bills and this module was extracted from
     * code that was unconditionally present. A default of `false` would have switched
     * billing off for every existing deployment on upgrade — the extraction is meant to
     * make the feature removable, not to remove it.
     *
     * This is not the same question as whether the person looking may see the figures.
     * That is `canReadBilling()` on their membership role, and both are asked.
     */
    'enabled' => env('CBOX_BILLING_ENABLED', true),

];
