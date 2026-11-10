import { createElement, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { defineCboxAppSwitcher } from '@cboxdk/cbox-ui/app-switcher';
import { useTranslator } from '@/i18n';
import type { SharedProps } from '@/types';

defineCboxAppSwitcher();

/**
 * The Switch-app control every Cbox app draws: `<cbox-app-switcher>` from
 * `@cboxdk/cbox-ui/app-switcher`. It reads the signed-in person's catalog from ID's own
 * same-origin endpoint (`apps.catalog`), the list `/api/v1/me/apps` serves to other apps,
 * with no OAuth round trip.
 */
export function AppSwitcher() {
    const { t } = useTranslator();
    const { appCatalogUrl = null } = usePage<SharedProps>().props;
    // An old `/switch-app` bookmark lands here with `?switch-app=1`: open the control.
    const fromBookmark = new URLSearchParams(window.location.search).get('switch-app') === '1';
    const opened = useCallback(
        (node: HTMLElement | null) => {
            if (node && fromBookmark)
                node.shadowRoot?.querySelector<HTMLButtonElement>('button')?.click();
        },
        [fromBookmark],
    );
    const labels = JSON.stringify({
        title: t('console.catalog_switch_app'),
        empty: t('console.catalog_empty'),
    });

    return createElement('cbox-app-switcher', {
        ref: opened,
        endpoint: appCatalogUrl ?? '',
        current: 'id',
        labels,
    });
}

/** ID's own mark in the rail, from the same catalog the switcher keeps. */
export function ServiceMark({ service }: { service: string }) {
    return createElement('cbox-service-mark', { service, 'aria-hidden': true });
}
