import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { AppErrorBoundary } from '@/chrome/AppErrorBoundary';
import type { SharedProps } from '@/types';

/**
 * THE CLIENT ENTRY. One bundle, one root, one mount.
 *
 * Pages are resolved lazily from `pages/`, so a visit downloads the shell plus the one
 * page it is going to — not the whole console. That is what pays for having no SSR: the
 * first paint is already correct (theme and brand tokens come from the server in `<head>`,
 * see `resources/views/app.blade.php`) and the JavaScript that follows is one page's
 * worth, not a hundred.
 */

const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');

void createInertiaApp<SharedProps>({
    /**
     * The server names a page the way the file tree spells it — `console/webhooks/show`
     * is `pages/console/webhooks/show.tsx`. A name with no file is a programming error,
     * so it throws by name rather than resolving to a blank screen that leaves the reader
     * guessing which of the hundred pages failed.
     */
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(
                `Inertia page "${name}" has no component. Expected resources/js/pages/${name}.tsx.`,
            );
        }

        return page().then((module) => module.default);
    },

    /**
     * The tab title. A page sets its own with `<Head title="…">`; this appends whose
     * console it is — the CUSTOMER's name on a branded sign-in, ours everywhere else —
     * so a person with six tabs open can tell two "Members" pages apart.
     *
     * The root view renders the same answer server-side for the first paint. This is the
     * refinement, not the source.
     */
    title: (title, page) => {
        const props = page.props as unknown as SharedProps;
        const suffix = props.brand?.name ?? props.app.name;

        return title ? `${title} · ${suffix}` : suffix;
    },

    progress: {
        color: 'var(--primary)',
        // A navigation that resolves quickly should show nothing at all; a bar that
        // flashes on every click reads as jank rather than as progress.
        delay: 250,
        showSpinner: false,
    },

    setup({ el, App, props }) {
        const app = (
            <AppErrorBoundary>
                <App {...props} />
            </AppErrorBoundary>
        );

        // `data-server-rendered` is not set today — there is no SSR — but hydrating when
        // it appears costs one branch and means turning SSR on later is a build change
        // rather than an edit here.
        if (el.hasAttribute('data-server-rendered')) {
            hydrateRoot(el, app);

            return;
        }

        createRoot(el).render(app);
    },
});
