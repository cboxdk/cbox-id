<?php

declare(strict_types=1);

return [

    /*
     * Where the console's "Read the guide" links point.
     *
     * The admin guides live in this repo under `docs/guides/`, so the default target
     * is the canonical GitHub view of them — a real, working URL from the first
     * deploy, with no docs site to stand up first. Point it at a rendered docs site
     * (and drop the suffix) once one exists:
     *
     *   DOCS_BASE_URL=https://cboxid.com/docs
     *   DOCS_LINK_SUFFIX=
     *
     * Set DOCS_BASE_URL to an empty string on an air-gapped deployment: every deep
     * link then disappears and the console falls back to its in-app explanations,
     * which are self-contained by design.
     */
    'base_url' => env('DOCS_BASE_URL', 'https://github.com/cboxdk/cbox-id/blob/main/docs'),

    /*
     * Appended to every documentation path. GitHub's blob view needs the `.md`;
     * a rendered site serves the same page without it.
     */
    'suffix' => env('DOCS_LINK_SUFFIX', '.md'),

];
