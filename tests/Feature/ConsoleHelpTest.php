<?php

declare(strict_types=1);

use App\Platform\ConsoleLocation;
use App\Platform\Help\DocsLinks;
use App\Platform\Help\HelpTopic;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('writes a real guide for every topic that claims to have one', function (): void {
    foreach (HelpTopic::cases() as $topic) {
        $path = $topic->docsPath();

        if ($path === null) {
            continue;
        }

        // A "Read the guide" link that 404s is worse than no link: it teaches people
        // the documentation is not there. The file has to exist in this repo.
        expect(base_path('docs/'.$path.'.md'))->toBeFile();
    }
});

it('gives every topic an explanation that stands on its own', function (): void {
    foreach (HelpTopic::cases() as $topic) {
        expect($topic->title())->not->toBe('')
            // Short enough to read in a popover, long enough to actually say something.
            ->and(strlen($topic->summary()))->toBeGreaterThan(80)
            ->and($topic->summary())->toEndWith('.');
    }
});

it('emits no documentation link when no docs site is configured', function (): void {
    config()->set('docs.base_url', '');

    expect(app(DocsLinks::class)->url(HelpTopic::SingleSignOn))->toBeNull();
});

it('builds a documentation link from the configured base and suffix', function (): void {
    config()->set('docs.base_url', 'https://example.test/docs/');
    config()->set('docs.suffix', '.md');

    expect(app(DocsLinks::class)->url(HelpTopic::SingleSignOn))
        ->toBe('https://example.test/docs/guides/single-sign-on.md');
});

it('never links a topic that has no guide behind it', function (): void {
    config()->set('docs.base_url', 'https://example.test/docs');

    expect(app(DocsLinks::class)->url(HelpTopic::Members))->toBeNull();
});

/**
 * The wayfinding bug this closes: a page's eyebrow is supposed to say which area of
 * the console you are in, and hand-written ones drifted from the sidebar labels that
 * got you there ("Authentication" above a page reached from "Sign-in").
 */
it('resolves a page eyebrow to the nav area that owns it', function (): void {
    expect(app(ConsoleLocation::class)->areaLabel('connections'))->toBe('Sign-in')
        ->and(app(ConsoleLocation::class)->areaLabel('clients'))->toBe('Developers')
        ->and(app(ConsoleLocation::class)->areaLabel('audit'))->toBe('Logs')
        // Sub-pages hang off their page's route name.
        ->and(app(ConsoleLocation::class)->areaLabel('connections.edit'))->toBe('Sign-in')
        ->and(app(ConsoleLocation::class)->areaLabel('not-a-console-route'))->toBeNull();
});

/**
 * Clicking "Stored tokens" and landing on a page headed "Token vault" reads as the
 * product being confusing. The nav label and the page's own title are one promise.
 */
it('keeps every nav label identical to its page title', function (): void {
    $expected = [
        'connections' => 'Single sign-on',
        'directories' => 'Sync users in',
        'provisioning' => 'Sync users out',
        'hooks' => 'Inline hooks',
        'vault' => 'Token vault',
        'sod-policies' => 'Role conflicts',
        'clients' => 'Apps & API keys',
        'audit' => 'Activity log',
    ];

    /*
     * EVERY PAGE IN THIS LIST IS CHECKED BEHAVIOURALLY NOW.
     *
     * Half of this sweep used to read a Volt blade for two literal strings, because under
     * Volt the tab title and the page heading were written twice and could drift apart. A
     * controller states the title once and `<PageHeader>` renders that same prop as the
     * h1, so they cannot disagree — and `ConsoleAreasTest` asserts the SERVED value
     * against this same nav label, which is the behavioural version of what was checked
     * here by reading a file.
     *
     * The nav label half stays here, because that is what this test is named for: the
     * promise "Stored tokens" in the sidebar makes about the page it opens.
     */
    $labels = [];

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            $labels[$page->route] = $page->label;
        }
    }

    $checkedElsewhere = [];

    foreach ($expected as $route => $label) {
        expect($labels[$route] ?? null)->toBe($label);

        // ROUTED TO A CONTROLLER, which is what "checked elsewhere" means: the served
        // title comes from one place, so the heading and the tab cannot disagree. A page
        // that fell back to a closure would drop out of this list and fail below.
        expect(Route::has($route))->toBeTrue("no route named {$route}");

        if (Route::getRoutes()->getByName($route)?->getActionName() !== 'Closure') {
            $checkedElsewhere[] = $route;
        }
    }

    // …and named, so this list shrinking is a decision somebody made rather than a sweep
    // quietly losing its subject.
    sort($checkedElsewhere);

    expect($checkedElsewhere)->toBe([
        'audit', 'clients', 'connections', 'directories', 'hooks',
        'provisioning', 'sod-policies', 'vault',
    ]);
});

/**
 * Resolve a repo-relative path the way Linux does, whatever the host filesystem thinks.
 *
 * Walks it segment by segment against `scandir`, because the obvious `realpath()` and
 * `file_exists()` are both case-INSENSITIVE on macOS: a link to `Roles.md` resolves on
 * the laptop that wrote it and 404s on the box that renders the site.
 */
function docsPathExists(string $repoRoot, string $relative): bool
{
    $current = $repoRoot;

    foreach (explode('/', $relative) as $segment) {
        $entries = @scandir($current);

        if ($entries === false || ! in_array($segment, $entries, true)) {
            return false;
        }

        $current .= '/'.$segment;
    }

    return true;
}

/**
 * A guide that points at a page which is not there is the same defect as a console
 * linking to a package that was never published: the reader follows it, finds nothing,
 * and has no way to tell whether they took a wrong turn or we did.
 *
 * Swept rather than spot-checked, because these break by RENAME — somebody moves a file
 * and every page that referenced it goes quietly dead, in a directory nothing else
 * compiles.
 *
 * Resolved TEXTUALLY and then checked case-sensitively, NOT with `realpath()`. realpath
 * answers two questions wrongly here, and both make the test pass on the machine that
 * wrote the link and fail on the one that publishes it: the case problem above, and that
 * it follows a path straight out of the repository. The first CI run of this test found
 * exactly that — `../../../laravel-id/docs/…`, which resolved on my laptop because that
 * repo happens to sit beside this one in ~/Projects, and for nobody else on earth. A
 * cross-repo reference has to be a URL; a link that leaves docs/ but stays in the repo
 * is fine.
 */
it('resolves every relative link between docs pages', function (): void {
    $repoRoot = base_path();
    $broken = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('docs')));

    foreach ($files as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.md')) {
            continue;
        }

        $directory = str_replace($repoRoot.'/', '', dirname($path));

        // Relative targets only: an absolute URL is somebody else's uptime, and an
        // anchor without a file is a link within the page.
        preg_match_all('/\]\(([^)#:]+\.md)(#[^)]*)?\)/', (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $target) {
            $segments = [];
            $escapes = false;

            foreach (explode('/', $directory.'/'.$target) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    if ($segments === []) {
                        $escapes = true;

                        break;
                    }

                    array_pop($segments);

                    continue;
                }

                $segments[] = $segment;
            }

            if ($escapes || ! docsPathExists($repoRoot, implode('/', $segments))) {
                $broken[] = str_replace($repoRoot.'/', '', $path).' → '.$target;
            }
        }
    }

    expect($broken)->toBe([]);
});
