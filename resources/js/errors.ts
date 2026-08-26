/**
 * THE ERROR PAGES' OWN SCRIPT — the two controls a rendered error page offers.
 *
 * These pages are Blade, not React, and deliberately: an error page must render when the
 * application is broken, which is exactly when a bundle that boots a router, resolves a
 * page component and hydrates is least likely to work. They are served by Laravel's own
 * exception handler with no props and no shared state.
 *
 * So they get their own tiny module rather than the console's. It used to be the whole of
 * `resources/js/app.js` — the Livewire-era bundle, 572 lines of theme toggling, passkey
 * ceremonies, copy buttons and an error overlay — kept alive after the port purely because
 * four error views loaded it for these two handlers.
 *
 * DELEGATED, so nothing here depends on when the DOM was built, and BUNDLED, so the strict
 * `script-src 'self'` in SecurityHeaders needs no inline exception.
 */
document.addEventListener('click', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    if (target.closest('[data-error-reload]') !== null) {
        event.preventDefault();
        window.location.reload();

        return;
    }

    /*
     * A trace id turns "something broke" into "here is the exact request to open in
     * telemetry". Copying it is the whole point of rendering it, and asking somebody to
     * select a 32-character hex string by hand on a page that has just failed them is not
     * the moment to make them work for it.
     */
    const copy = target.closest('[data-copy-trace]');

    if (copy === null) {
        return;
    }

    event.preventDefault();

    const traceId = copy.getAttribute('data-copy-trace');

    if (traceId === null || navigator.clipboard === undefined) {
        return;
    }

    navigator.clipboard
        .writeText(traceId)
        .then(() => {
            const feedback = copy.parentElement?.querySelector('[data-copy-feedback]');

            if (!(feedback instanceof HTMLElement)) {
                return;
            }

            feedback.style.visibility = 'visible';
            setTimeout(() => {
                feedback.style.visibility = 'hidden';
            }, 1600);
        })
        // A refused clipboard permission is not something to report on an error page: the
        // id is on screen and selectable either way.
        .catch(() => {});
});
