<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Help\DocsLinks;
use Illuminate\Http\RedirectResponse;

/**
 * `/docs` and `/docs/{page}` on this deployment — a redirect to where the documentation is
 * published.
 *
 * The documentation is not served by this application: it is `docs/` in the repository,
 * rendered by the docs site `docs.base_url` names (cbox.dk by default, see config/docs.php).
 * But `https://cboxid.com/docs` is the address people type, and it was printed in this
 * repository as the example of a docs site — and answered with a 404. This makes it the
 * front door it looks like: `/docs` lands on the documentation's front page and
 * `/docs/guides/roles` on that guide, built by the same {@see DocsLinks} the console's
 * "Read the guide" links use, so the two can never disagree about where a page is.
 *
 * A 302, not a 301: where the docs are published is configuration, and a browser that
 * cached a permanent redirect would keep following the old answer after it changed.
 *
 * Nothing from the request can choose the host. The page is a path of letters, digits,
 * `_`, `-` and `/` (the route's constraint) appended to the configured base, so `/docs`
 * cannot be turned into an open redirect. A trailing `.md` — a link copied from GitHub
 * or from a source file — is dropped, since the published site serves the page without
 * it and the configured suffix adds one back where it is wanted.
 *
 * With no docs site configured (an air-gapped install blanks `DOCS_BASE_URL`) there is
 * nowhere to send anyone, and the answer is the 404 it always was.
 */
final class DocsRedirectController extends Controller
{
    public function __invoke(DocsLinks $docs, ?string $page = null): RedirectResponse
    {
        $page = trim((string) $page, '/');

        if (str_ends_with($page, '.md')) {
            $page = substr($page, 0, -3);
        }

        $target = $page === '' ? $docs->home() : $docs->page($page);

        abort_if($target === null, 404);

        return redirect()->away($target);
    }
}
