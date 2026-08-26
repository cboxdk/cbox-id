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

/**
 * AND THE MODULES' OWN PAGES, under the namespace they already use for views.
 *
 * A module ships its routes, its service provider and its blade views together; its React
 * pages belong beside them for the same reason. `connectors::connections` resolves to
 * `modules/connectors/resources/js/pages/connections.tsx`, which is the exact spelling
 * blade's `connectors::components.…` already uses — so there is one namespace convention in
 * this repository rather than two.
 */
const modulePages = import.meta.glob<{ default: ResolvedComponent }>(
    '../../modules/*/resources/js/pages/**/*.tsx',
);

void createInertiaApp<SharedProps>({
    /**
     * The server names a page the way the file tree spells it — `console/webhooks/show`
     * is `pages/console/webhooks/show.tsx`. A name with no file is a programming error,
     * so it throws by name rather than resolving to a blank screen that leaves the reader
     * guessing which of the hundred pages failed.
     */
    resolve: (name) => {
        const [namespace, path] = name.includes('::') ? name.split('::') : [null, name];

        const page =
            namespace === null
                ? pages[`./pages/${path}.tsx`]
                : modulePages[`../../modules/${namespace}/resources/js/pages/${path}.tsx`];

        if (page === undefined) {
            throw new Error(
                namespace === null
                    ? `Inertia page "${name}" has no component. Expected resources/js/pages/${path}.tsx.`
                    : `Inertia page "${name}" has no component. Expected modules/${namespace}/resources/js/pages/${path}.tsx.`,
            );
        }

        return page().then((module) => module.default);
    },

    /**
     * THE TAB TITLE, and the same statement the root view already rendered.
     *
     * The server names the page (`title`, set by the console controller) and says which
     * SECTION it belongs to — the word that distinguishes the whole install from one
     * customer on it, because half the platform pages share a name with a page about the
     * operator's own organization. Both are read here so the title after hydration is
     * byte-for-byte what was in `<head>` on the first paint.
     *
     * A page may still override with `<Head title="…">` — the detail pages that are named
     * after the thing they are showing do — and that wins, because it is more specific
     * than anything the controller could state.
     */
    title: (title, page) => {
        const props = page.props as unknown as SharedProps & { title?: string };
        const own = title !== '' ? title : (props.title ?? '');
        const suffix = props.brand?.name ?? props.app.name;

        return [own, props.shell?.section, suffix].filter(Boolean).join(' · ');
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
