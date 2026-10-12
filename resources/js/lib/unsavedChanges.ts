import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** The parts of an Inertia visit that decide whether it leaves the page. */
export interface VisitShape {
    method: string;
    url: URL;
    only: string[];
    except: string[];
    prefetch: boolean;
}

/**
 * Whether a visit takes the person AWAY from the page they are editing.
 *
 * A form's own POST is the save, not a departure; a partial reload (`only`/`except`) or a
 * prefetch on hover redraws or warms a page and replaces nothing on screen. Everything
 * else — a link in the rail, the command palette, a redirect asked for by a button — is
 * a GET for another page, which would drop the draft without a word.
 */
export function leavesPage(visit: VisitShape): boolean {
    return (
        visit.method.toLowerCase() === 'get' &&
        visit.only.length === 0 &&
        visit.except.length === 0 &&
        !visit.prefetch
    );
}

export interface UnsavedChangesGuard {
    /** Where the person was going when they were stopped, or null. */
    pending: string | null;
    /** Go there anyway — the draft is dropped. */
    leave: () => void;
    /** Stay and keep editing. */
    stay: () => void;
}

/**
 * Keep a page's unsaved changes from being lost to a click elsewhere.
 *
 * Two doors out of a page, two guards. Closing the tab, reloading or typing an address is
 * the browser's own, and only the browser's own prompt can stop it (`beforeunload`; the
 * wording is the browser's, by design). Following a link inside the console is an Inertia
 * visit, which is held here so the page can ask in its own words — `pending` is where the
 * person was going, `leave()` goes there, `stay()` forgets it.
 */
export function useUnsavedChangesGuard(dirty: boolean): UnsavedChangesGuard {
    const [pending, setPending] = useState<string | null>(null);
    const dirtyRef = useRef(dirty);
    const bypass = useRef(false);

    useEffect(() => {
        dirtyRef.current = dirty;

        if (!dirty) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent): void => {
            event.preventDefault();
            // Chrome before 119 and Safari still want the legacy assignment.
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    useEffect(
        () =>
            router.on('before', (event) => {
                const visit = event.detail.visit;

                if (!dirtyRef.current || bypass.current || !leavesPage(visit)) {
                    return;
                }

                event.preventDefault();
                setPending(visit.url.href);
            }),
        [],
    );

    const leave = useCallback(() => {
        if (pending === null) {
            return;
        }

        bypass.current = true;
        setPending(null);
        router.visit(pending, {
            onFinish: () => {
                bypass.current = false;
            },
        });
    }, [pending]);

    const stay = useCallback(() => setPending(null), []);

    return { pending, leave, stay };
}
