<?php

declare(strict_types=1);

use App\Platform\Connect\QuickstartFramework;
use App\Platform\Help\DocsLinks;
use App\Platform\Help\HelpTopic;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| EVERY LINK THE PRODUCT ITSELF PRINTS LANDS
|--------------------------------------------------------------------------
|
| DocsLinksTest holds the documentation's own links. This holds the ones the APP renders:
| the console's "Read the guide" links and the Get started page's quickstart link, the
| `/docs` address on every host, and every path written as a literal into a page, a mail
| template, a redirect or a prop — the places a rename leaves a 404 that no route test
| notices, because no test ever follows a link.
|
| The production report this closes: "many dead links". `https://cboxid.com/docs` was the
| example config/docs.php gave for a docs site, and it answered 404 — the docs are
| published on cbox.dk, which nothing pointed at. The console's guide links went to
| GitHub's source view instead.
|
| WHAT IS NOT HERE: absolute URLs of other people's sites (an identity provider's setup
| guide in the framework's catalogue) — they are somebody else's uptime, a network call
| from the suite is a flaky suite, and the framework's own tests own its catalogue. And
| the code in app/Platform/Connect, which is code for the CUSTOMER's app
| (`redirect('/dashboard')` is a page of theirs, not ours).
*/

/** Whether $path is something this application answers with a GET: a route or a public file. */
function productPathResolves(string $path): bool
{
    $path = (string) strtok($path, '?#');

    if ($path !== '/' && is_file(public_path(ltrim($path, '/')))) {
        return true;
    }

    try {
        app('router')->getRoutes()->match(Request::create($path, 'GET'));

        return true;
    } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
        // A BASE URL — `/scim/v2`, printed for an identity provider to append `/Users` to
        // — is not a page of its own, and must not be: SCIM answers its base with a 404.
        // It is alive when routes live under it.
        $prefix = trim($path, '/').'/';

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Every root-relative path written as a literal link in the product's source, as
 * `file → path`.
 *
 * Only COMPLETE literals: `url('/sso/saml/'.$id)` is a prefix, not a link, and a path with
 * a `{placeholder}` or `${…}` is built at runtime — the route() helpers that build those
 * throw on a missing route already.
 *
 * @return list<array{0: string, 1: string}>
 */
function productLiteralLinks(): array
{
    $patterns = [
        // React: href="/x", href={'/x'}, href={`/x`}, { href: '/x' }, router.visit('/x').
        'tsx' => [
            '/\bhref=\{?["\'`](\/[^"\'`]*)["\'`]\}?/',
            '/\b(?:href|url|to):\s*["\'`](\/[^"\'`]*)["\'`]/',
            '/\brouter\.(?:visit|get)\(\s*["\'`](\/[^"\'`]*)["\'`]/',
            '/\bwindow\.location(?:\.href)?\s*=\s*["\'`](\/[^"\'`]*)["\'`]/',
        ],
        // Blade: href="/x" and src="/x" outside {{ }}.
        'blade.php' => [
            '/\b(?:href|src)="(\/[^"{]*)"/',
        ],
        // PHP: url('/x'), redirect('/x'), ->to('/x'), Route::redirect(…, '/x'), 'href' => '/x'.
        'php' => [
            '/\b(?:url|redirect)\(\s*\'(\/[^\']*)\'\s*\)/',
            '/->to\(\s*\'(\/[^\']*)\'\s*[,)]/',
            '/Route::(?:permanentR|r)edirect\(\s*\'[^\']*\',\s*\'(\/[^\']*)\'/',
            '/\'(?:href|url|link|return_to)\'\s*=>\s*\'(\/[^\']*)\'/',
        ],
    ];

    $roots = [
        'tsx' => [resource_path('js')],
        'blade.php' => [resource_path('views')],
        'php' => [app_path(), base_path('routes'), base_path('modules')],
    ];

    $found = [];

    foreach ($roots as $extension => $directories) {
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = $file->getPathname();
                $relative = substr($path, strlen(base_path()) + 1);

                if (! str_ends_with($path, '.'.$extension)
                    || ($extension === 'php' && str_ends_with($path, '.blade.php'))
                    || preg_match('#(\.test\.tsx$|/__tests__/|/test/)#', $relative) === 1
                    // Code for the customer's app, not links of ours.
                    || str_starts_with($relative, 'app/Platform/Connect/')
                    // The design-system gallery exists only on a local install
                    // (DesignSystemRouteTest), and its links are sample data.
                    || str_starts_with($relative, 'resources/js/pages/dev/')) {
                    continue;
                }

                $source = (string) file_get_contents($path);

                foreach ($patterns[$extension] as $pattern) {
                    preg_match_all($pattern, $source, $matches);

                    foreach ($matches[1] as $link) {
                        if (str_contains($link, '{') || str_contains($link, '$') || str_starts_with($link, '//')) {
                            continue;
                        }

                        $found[] = [$relative, $link];
                    }
                }
            }
        }
    }

    return $found;
}

it('finds the literal links it is meant to check', function (): void {
    $links = array_map(static fn (array $found): string => $found[1], productLiteralLinks());

    // A sweep that silently matched nothing would pass forever. These are known to be
    // written as literals today; if one is rewritten as a route() call, swap it for another.
    expect($links)->toContain('/api/v1/environment/openapi.yaml')
        ->toContain('/api/v1/workspace/openapi.yaml')
        ->toContain('/projects');
});

it('resolves every literal link the product renders to a route or a public file', function (): void {
    installedDeployment();

    $dead = [];

    foreach (productLiteralLinks() as [$file, $link]) {
        if (! productPathResolves($link)) {
            $dead[] = "{$file} → {$link}";
        }
    }

    expect($dead)->toBe([]);
});

it('writes a docs page for every quickstart the Get started page links', function (): void {
    foreach (QuickstartFramework::cases() as $framework) {
        expect(base_path('docs/'.$framework->docsPath().'.md'))->toBeFile();
    }
});

it('links every guide and quickstart to the published docs site, not to a source view', function (): void {
    config()->set('docs.base_url', 'https://cbox.dk/products/cbox-id/docs');
    config()->set('docs.suffix', '');

    $docs = app(DocsLinks::class);

    expect($docs->url(HelpTopic::Roles))->toBe('https://cbox.dk/products/cbox-id/docs/guides/roles')
        ->and($docs->page(QuickstartFramework::Laravel->docsPath()))->toBe('https://cbox.dk/products/cbox-id/docs/quickstarts/laravel')
        ->and($docs->home())->toBe('https://cbox.dk/products/cbox-id/docs');

    // Every link the help layer can emit is a page that exists in docs/ — which the docs
    // site publishes under the same path.
    foreach (HelpTopic::cases() as $topic) {
        $url = $docs->url($topic);

        if ($url === null) {
            continue;
        }

        $path = substr($url, strlen('https://cbox.dk/products/cbox-id/docs/'));

        expect(base_path('docs/'.$path.'.md'))->toBeFile();
    }
});

it('ships the published docs site as the default, the address the console links', function (): void {
    // Read without the environment's own DOCS_* so the assertion is about the default.
    $defaults = (static function (): array {
        $saved = [$_ENV['DOCS_BASE_URL'] ?? null, $_SERVER['DOCS_BASE_URL'] ?? null, getenv('DOCS_BASE_URL'), $_ENV['DOCS_LINK_SUFFIX'] ?? null, $_SERVER['DOCS_LINK_SUFFIX'] ?? null, getenv('DOCS_LINK_SUFFIX')];

        unset($_ENV['DOCS_BASE_URL'], $_SERVER['DOCS_BASE_URL'], $_ENV['DOCS_LINK_SUFFIX'], $_SERVER['DOCS_LINK_SUFFIX']);
        putenv('DOCS_BASE_URL');
        putenv('DOCS_LINK_SUFFIX');

        try {
            return require config_path('docs.php');
        } finally {
            [$envBase, $serverBase, $getenvBase, $envSuffix, $serverSuffix, $getenvSuffix] = $saved;

            foreach (['DOCS_BASE_URL' => [$envBase, $serverBase, $getenvBase], 'DOCS_LINK_SUFFIX' => [$envSuffix, $serverSuffix, $getenvSuffix]] as $name => [$env, $server, $process]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }

                if (is_string($process)) {
                    putenv($name.'='.$process);
                }
            }
        }
    })();

    expect($defaults)->toBe([
        'base_url' => 'https://cbox.dk/products/cbox-id/docs',
        'suffix' => '',
    ]);
});

/*
 * `/docs` on this host — what somebody types after the domain, and what config/docs.php
 * used to print as the example docs site while it answered 404.
 */
it('redirects /docs to the documentation\'s front page', function (): void {
    installedDeployment();
    config()->set('docs.base_url', 'https://cbox.dk/products/cbox-id/docs/');

    $this->get('/docs')->assertRedirect('https://cbox.dk/products/cbox-id/docs');
});

it('redirects /docs/{page} to that page, with the suffix the site wants', function (): void {
    installedDeployment();
    config()->set('docs.base_url', 'https://cbox.dk/products/cbox-id/docs');
    config()->set('docs.suffix', '');

    $this->get('/docs/guides/single-sign-on')->assertRedirect('https://cbox.dk/products/cbox-id/docs/guides/single-sign-on');
    // A link copied from GitHub or a source file keeps working.
    $this->get('/docs/guides/single-sign-on.md')->assertRedirect('https://cbox.dk/products/cbox-id/docs/guides/single-sign-on');

    config()->set('docs.base_url', 'https://github.com/cboxdk/cbox-id/blob/main/docs');
    config()->set('docs.suffix', '.md');

    $this->get('/docs/guides/roles')->assertRedirect('https://github.com/cboxdk/cbox-id/blob/main/docs/guides/roles.md');
});

it('answers /docs on an install nobody has claimed yet, instead of pointing it at first run', function (): void {
    config()->set('docs.base_url', 'https://cbox.dk/products/cbox-id/docs');
    config()->set('docs.suffix', '');

    $this->get('/docs/self-hosting/quickstart')->assertRedirect('https://cbox.dk/products/cbox-id/docs/self-hosting/quickstart');
});

it('answers 404 at /docs when no docs site is configured', function (): void {
    installedDeployment();
    config()->set('docs.base_url', '');

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/guides/roles')->assertNotFound();
});

it('cannot be steered off the docs site', function (): void {
    installedDeployment();
    config()->set('docs.base_url', 'https://cbox.dk/products/cbox-id/docs');
    config()->set('docs.suffix', '');

    // Only letters, digits, `_`, `-` and `/` reach the controller; anything that could
    // name another host or climb out of the base is not a docs page.
    $this->get('/docs/..%2F..%2Fevil.example')->assertNotFound();
    $this->get('/docs/guides/roles?x=https://evil.example')->assertRedirect('https://cbox.dk/products/cbox-id/docs/guides/roles');
    $this->get('/docs//evil.example')->assertNotFound();
})->group('security');
