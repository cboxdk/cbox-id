import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * SAYING THE PAGE CHANGED.
 *
 * A full page load announces itself: the browser resets focus to the document and a
 * screen reader reads the new title. A client-side navigation does neither — the DOM is
 * swapped underneath somebody who was never told, and focus stays wherever they left it,
 * often on a link that no longer exists.
 *
 * This is the standard remedy and it is not optional in a console this size: a live
 * region that speaks the new page's title after every visit. The Volt console got it for
 * free from `wire:navigate`, which does the same thing; nothing in Inertia does.
 *
 * FOCUS IS DELIBERATELY NOT MOVED. Sending it to the main landmark is the other half of
 * this pattern and it fights the two navigations that dominate here — a filter that
 * re-renders a list while somebody is still typing in the search box, and a partial
 * reload that changes nothing they can see. The announcement tells them; taking their
 * cursor away would not help.
 */
export function RouteAnnouncer() {
    const [announcement, setAnnouncement] = useState('');

    useEffect(() => {
        // `success` rather than `finish`: a cancelled or failed visit changed no page, and
        // announcing one that never arrived is worse than silence.
        const stop = router.on('success', (event) => {
            const title = document.title;

            // Reset first. A live region whose text is replaced with the SAME string is
            // not re-announced, and two consecutive visits to pages with one title —
            // paging through a list, say — would then say nothing at all.
            setAnnouncement('');

            // Deliberately not in a `requestAnimationFrame`: the reset above and this must
            // land in two separate commits, which a microtask gives and a frame callback
            // would collapse under React's batching.
            setTimeout(() => setAnnouncement(title), 60);

            return event;
        });

        return () => stop();
    }, []);

    return (
        <p aria-live="polite" aria-atomic="true" className="sr-only">
            {announcement}
        </p>
    );
}
