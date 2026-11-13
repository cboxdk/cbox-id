import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { listen, type LiveMessage } from '@/lib/realtime';
import { hasUnsavedChanges } from '@/lib/unsavedChanges';
import type { SharedProps } from '@/types';

/** Changes arriving within this window are one reload. */
export const COALESCE_MS = 500;

/** …and reloads start at least this far apart, however busy the channel. */
export const MIN_GAP_MS = 1000;

export interface LiveReloadOptions {
    /** Reload only these props (an Inertia partial reload). Default: the page's own props. */
    only?: string[];
    /** Listen on these channels only. Default: every channel the page was offered. */
    channels?: string[];
}

export interface LiveReload {
    /** Something changed while the person was editing: offer a refresh, do not force one. */
    stale: boolean;
    /** Reload now and clear the offer. */
    refresh: () => void;
}

/**
 * Whether reloading now could take something out from under the person: a guarded form with
 * unsaved changes, a field or editable region with focus, or an open dialog.
 */
export function isEditing(doc: Document = document): boolean {
    if (hasUnsavedChanges()) {
        return true;
    }

    const active = doc.activeElement;

    if (
        active instanceof HTMLElement &&
        (active.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName))
    ) {
        return true;
    }

    return doc.querySelector('[role="dialog"], [role="alertdialog"], dialog[open]') !== null;
}

/**
 * RELOAD THIS PAGE WHEN ITS DATA CHANGES.
 *
 * `topics` are the kinds of record the page shows (`devices`, `members`, …; see
 * `App\Platform\Realtime\RecordTopics`). A matching signal on any of the page's channels
 * reloads its props through Inertia — the same request, through the same gates, as
 * opening the page — coalesced so a burst is one reload.
 *
 * Never under an edit: if the person is typing, has a dialog open or a form with unsaved
 * changes, the page is marked `stale` instead and the person chooses when to refresh. A
 * hidden tab waits until it is looked at.
 */
export function useLiveReload(
    topics: readonly string[],
    options: LiveReloadOptions = {},
): LiveReload {
    const { realtime } = usePage<SharedProps>().props;
    const [stale, setStale] = useState(false);
    const timer = useRef<number | null>(null);
    const lastStart = useRef(0);
    const inFlight = useRef(false);
    const again = useRef(false);
    const hiddenPending = useRef(false);
    const scheduleRef = useRef<() => void>(() => undefined);
    const only = options.only;
    const topicKey = topics.join(',');
    const onlyKey = only?.join(',') ?? '';
    const channelKey = (options.channels ?? realtime?.channels ?? []).join(',');

    const reload = useCallback(() => {
        if (inFlight.current) {
            again.current = true;

            return;
        }

        inFlight.current = true;
        lastStart.current = Date.now();

        router.reload({
            ...(only !== undefined && only.length > 0 ? { only } : {}),
            async: true,
            showProgress: false,
            onFinish: () => {
                inFlight.current = false;

                if (again.current) {
                    again.current = false;
                    scheduleRef.current();
                }
            },
        });
        // `only` is read through its key: a new array with the same names is the same reload.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [onlyKey]);

    const run = useCallback(() => {
        timer.current = null;

        if (document.hidden) {
            hiddenPending.current = true;

            return;
        }

        if (isEditing()) {
            setStale(true);

            return;
        }

        reload();
    }, [reload]);

    const schedule = useCallback(() => {
        if (timer.current !== null) {
            return;
        }

        const wait = Math.max(COALESCE_MS, lastStart.current + MIN_GAP_MS - Date.now());
        timer.current = window.setTimeout(run, wait);
    }, [run]);
    scheduleRef.current = schedule;

    useEffect(() => {
        if (realtime === null || realtime === undefined || topicKey === '' || channelKey === '') {
            return;
        }

        const wanted = new Set(topicKey.split(','));
        const onMessage = (message: LiveMessage): void => {
            if (message.topics.some((topic) => wanted.has(topic))) {
                schedule();
            }
        };
        const stops = channelKey.split(',').map((channel) => listen(realtime, channel, onMessage));

        const onVisible = (): void => {
            if (!document.hidden && hiddenPending.current) {
                hiddenPending.current = false;
                schedule();
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            stops.forEach((stop) => stop());
            document.removeEventListener('visibilitychange', onVisible);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                timer.current = null;
            }
        };
        // `realtime` is compared by what it connects to, not by identity: a partial reload
        // hands the page a new object with the same contents.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [realtime?.key, realtime?.authUrl, topicKey, channelKey, schedule]);

    const refresh = useCallback(() => {
        setStale(false);
        reload();
    }, [reload]);

    return { stale, refresh };
}
