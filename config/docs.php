<?php

declare(strict_types=1);

return [

    /*
     * Where the console's "Read the guide" links, the Get started page's quickstart link
     * and this deployment's own `/docs` point.
     *
     * The guides live in this repository under `docs/`, and cbox.dk publishes them: its
     * docs site imports `docs/` from every release (and from `main`, as the `dev`
     * version) and renders `docs/guides/roles.md` at
     * `https://cbox.dk/products/cbox-id/docs/guides/roles` — the newest release's page,
     * without the `.md`. That is the default, so a link lands on the site people read
     * the documentation on rather than on a GitHub source view.
     *
     * `https://cboxid.com/docs` used to be printed here as the example of "a rendered
     * site" while nothing answered it, and it 404'd for whoever tried it. The application
     * now answers `/docs` and `/docs/{page}` itself, with a redirect to the same place
     * ({@see \App\Http\Controllers\DocsRedirectController}), so that address works too.
     *
     * To link the repository's source view instead (a fork, or an install that wants the
     * page exactly as it stands on `main`):
     *
     *   DOCS_BASE_URL=https://github.com/cboxdk/cbox-id/blob/main/docs
     *   DOCS_LINK_SUFFIX=.md
     *
     * Set DOCS_BASE_URL to an empty string on an air-gapped deployment: every deep
     * link then disappears, `/docs` answers 404, and the console falls back to its
     * in-app explanations, which are self-contained by design.
     */
    'base_url' => env('DOCS_BASE_URL', 'https://cbox.dk/products/cbox-id/docs'),

    /*
     * Appended to every documentation path. The rendered site serves a page without an
     * extension; GitHub's blob view needs `.md`.
     */
    'suffix' => env('DOCS_LINK_SUFFIX', ''),

];
