<?php

declare(strict_types=1);

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/*
|--------------------------------------------------------------------------
| EVERY RELATIVE LINK IN docs/ LANDS, AND SO DOES EVERY ANCHOR
|--------------------------------------------------------------------------
|
| A guide that points at a page which is not there is the same defect as a console
| linking to a package that was never published: the reader follows it, finds nothing,
| and has no way to tell whether they took a wrong turn or we did. An anchor that is
| not there is the quieter version — the page loads, at the top, and the reader scrolls
| looking for a section that was renamed a month ago.
|
| Swept rather than spot-checked, because these break by RENAME: somebody moves a file
| or retitles a heading and every page that referenced it goes quietly dead, in a
| directory nothing else compiles.
|
| Three rules, each one a way a link that works on the laptop that wrote it fails on the
| site that renders it:
|
|  - RESOLVED TEXTUALLY AND CHECKED CASE-SENSITIVELY, not with `realpath()`. realpath
|    is case-INSENSITIVE on macOS, so a link to `Roles.md` resolves locally and 404s on
|    Linux; and it follows a path straight out of the repository — the first run of the
|    older version of this test found `../../../laravel-id/docs/…`, which resolved only
|    because that repository happened to sit beside this one on one machine.
|  - IT STAYS INSIDE docs/. The docs site renders this directory and nothing beside it,
|    so a link to `../../resources/…` is a 404 there however well it works on GitHub. A
|    reference to anything else — another repo, a source file — is a canonical URL.
|  - AN ANCHOR MUST LAND ON BOTH RENDERERS. The docs are read in two places: GitHub, and
|    the docs site the console's "Read the guide" links open (cbox.dk, which renders
|    docs/ with league/commonmark — config/docs.php). They slug a heading differently:
|    GitHub keeps `_` and turns every space into a hyphen; commonmark drops `_` and
|    collapses a run of spaces. So an anchor is checked against GitHub's slugs AND the
|    ids the docs site's own renderer gives the page, rendered here with the same
|    extensions and options. An explicit `<a id="…">` counts on both. (The actions
|    reference linked `#organizationsportal_linkscreate` — GitHub's slug — and all 79 of
|    its underscored Contents links were dead on cbox.dk while this test, checking
|    GitHub's rule alone, passed.)
|
| Links inside fenced code blocks and inline code are examples, not links, and are
| skipped. Absolute URLs are somebody else's uptime and are not fetched.
*/

/**
 * Every Markdown file under docs/, relative to the repository root.
 *
 * @return list<string>
 */
function docsMarkdownFiles(): array
{
    $files = [];

    /** @var iterable<SplFileInfo> $found */
    $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('docs'), FilesystemIterator::SKIP_DOTS));

    foreach ($found as $file) {
        $path = $file->getPathname();

        if (str_ends_with($path, '.md')) {
            $files[] = substr($path, strlen(base_path()) + 1);
        }
    }

    sort($files);

    return $files;
}

/** The Markdown with fenced code blocks, HTML comments and inline code blanked out. */
function docsProse(string $markdown): string
{
    $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
    $out = [];
    $fence = null;

    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match) === 1) {
            $marker = $match[1][0];

            if ($fence === null) {
                $fence = $marker;
                $out[] = '';

                continue;
            }

            if ($fence === $marker) {
                $fence = null;
                $out[] = '';

                continue;
            }
        }

        $out[] = $fence === null ? (string) preg_replace('/`[^`]*`/', '``', $line) : '';
    }

    return implode("\n", $out);
}

/**
 * The anchors a page offers: each heading's slug, with GitHub's duplicate suffixes, and
 * every explicit `id="…"`.
 *
 * @return list<string>
 */
function docsAnchors(string $markdown): array
{
    $anchors = [];
    $seen = [];

    // Headings are read from the text with code fences removed but inline code KEPT —
    // a heading's code span is part of its slug.
    $withoutFences = [];
    $fence = null;

    foreach (explode("\n", (string) preg_replace('/<!--.*?-->/s', '', $markdown)) as $line) {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match) === 1) {
            $fence = $fence === null ? $match[1][0] : ($fence === $match[1][0] ? null : $fence);

            continue;
        }

        if ($fence === null) {
            $withoutFences[] = $line;
        }
    }

    foreach ($withoutFences as $line) {
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $match) !== 1) {
            continue;
        }

        // A link in a heading contributes its text; an image nothing.
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $match[1]);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = strip_tags($text);

        $slug = mb_strtolower(trim($text));
        $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug);
        $slug = str_replace(' ', '-', $slug);

        $count = $seen[$slug] ?? 0;
        $anchors[] = $count === 0 ? $slug : $slug.'-'.$count;
        $seen[$slug] = $count + 1;
    }

    preg_match_all('/\sid="([^"]+)"/', $markdown, $ids);

    return [...$anchors, ...$ids[1]];
}

/**
 * The ids the docs site gives a page: the page rendered by league/commonmark with the
 * options cbox.dk renders docs/ with (CommonMark core, GitHub-flavoured Markdown, heading
 * permalinks with no prefix), front matter stripped first as it does.
 *
 * @return list<string>
 */
function docsSiteAnchors(string $markdown): array
{
    $environment = new Environment([
        'heading_permalink' => [
            'id_prefix' => '',
            'apply_id_to_heading' => true,
            'fragment_prefix' => '',
            'insert' => 'none',
            'symbol' => '',
        ],
    ]);

    $environment->addExtension(new CommonMarkCoreExtension);
    $environment->addExtension(new GithubFlavoredMarkdownExtension);
    $environment->addExtension(new HeadingPermalinkExtension);

    $markdown = (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $markdown);
    $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    return array_map(static fn (string $id): string => html_entity_decode($id, ENT_QUOTES | ENT_HTML5), $ids[1]);
}

/**
 * Resolve $target against the directory $from, textually. Null when it climbs out of
 * the repository.
 */
function docsResolve(string $from, string $target): ?string
{
    $segments = [];

    foreach (explode('/', $from.'/'.$target) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            if ($segments === []) {
                return null;
            }

            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/** Whether $relative exists below the repository root, compared case-sensitively at every segment. */
function docsExists(string $relative): bool
{
    $current = base_path();

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
 * Every link target on a page: inline links and images, and reference definitions.
 *
 * @return list<string>
 */
function docsLinkTargets(string $prose): array
{
    preg_match_all('/!?\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $prose, $inline);
    preg_match_all('/^\s{0,3}\[[^\]]+\]:\s*<?(\S+?)>?(?:\s+"[^"]*")?\s*$/m', $prose, $definitions);
    preg_match_all('/<(?:img|a)\s[^>]*(?:src|href)="([^"]+)"/i', $prose, $html);

    return [...$inline[1], ...$definitions[1], ...$html[1]];
}

/**
 * Every broken relative link or anchor on $files. A link must land inside $within (a
 * directory prefix such as `docs/`, or `` for anywhere in the repository).
 *
 * @param  list<string>  $files
 * @return list<string>
 */
function docsBrokenLinks(array $files, string $within): array
{
    $broken = [];
    $anchors = [];

    foreach ($files as $file) {
        $prose = docsProse((string) file_get_contents(base_path($file)));

        foreach (docsLinkTargets($prose) as $target) {
            // Absolute URLs and other schemes (https:, mailto:) are not ours to check.
            if (preg_match('/^[a-z][a-z0-9+.-]*:|^\/\//i', $target) === 1) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $path = rawurldecode((string) $path);

            $resolved = $path === '' ? $file : docsResolve(dirname($file), $path);

            if ($resolved === null || ! str_starts_with($resolved.'/', $within)) {
                $broken[] = "{$file} → {$target} (leaves ".($within === '' ? 'the repository' : $within).'; link to a canonical URL instead)';

                continue;
            }

            if (! docsExists($resolved)) {
                $broken[] = "{$file} → {$target} (no such file)";

                continue;
            }

            if ($anchor === null || $anchor === '') {
                continue;
            }

            if (! str_ends_with($resolved, '.md')) {
                $broken[] = "{$file} → {$target} (an anchor into a file that is not a page)";

                continue;
            }

            $page = (string) file_get_contents(base_path($resolved));
            $anchors[$resolved] ??= [docsAnchors($page), docsSiteAnchors($page)];
            [$onGitHub, $onSite] = $anchors[$resolved];
            $wanted = rawurldecode($anchor);

            if (! in_array($wanted, $onGitHub, true)) {
                $broken[] = "{$file} → {$target} (no heading with that anchor on GitHub)";
            }

            if (! in_array($wanted, $onSite, true)) {
                $broken[] = "{$file} → {$target} (no heading with that anchor on the docs site)";
            }
        }
    }

    return $broken;
}

it('resolves every relative link and anchor in docs/', function (): void {
    expect(docsBrokenLinks(docsMarkdownFiles(), 'docs/'))->toBe([]);
});

// The README is the front door on GitHub and Packagist; its links may point anywhere in
// the repository (LICENSE, SECURITY.md), but they must land. So must the changelog's and
// the upgrade guide's, which the docs site renders as the release notes.
it('resolves every relative link and anchor in the README, changelog and upgrade guide', function (): void {
    expect(docsBrokenLinks(['README.md', 'UPGRADING.md', 'CHANGELOG.md', 'SECURITY.md'], ''))->toBe([]);
});

it('slugs headings the way the renderer does', function (): void {
    expect(docsAnchors(implode("\n", [
        '# The IdP-surface gate: the apex host 404s the protocol surface',
        '## `cbox id` and the **CLI**',
        '## Step 1. Create the [app](x.md)',
        '## Overview',
        '## Overview',
        '```',
        '# not a heading',
        '```',
        '<a id="custom-anchor"></a>',
    ])))->toBe([
        'the-idp-surface-gate-the-apex-host-404s-the-protocol-surface',
        'cbox-id-and-the-cli',
        'step-1-create-the-app',
        'overview',
        'overview-1',
        'custom-anchor',
    ]);
});

it('slugs headings the way the docs site does, which is not always GitHub\'s way', function (): void {
    $page = implode("\n", [
        '---',
        'title: Front matter is not a heading',
        '---',
        '### organizations.portal_links.create',
        '### <a id="team.transfer_ownership"></a>team.transfer_ownership',
        '## Admin Portal',
    ]);

    expect(docsSiteAnchors($page))->toBe([
        'organizationsportallinkscreate',
        'teamtransferownership',
        'team.transfer_ownership',
        'admin-portal',
    ])
        // GitHub keeps the underscore: the one anchor the two disagree on.
        ->and(docsAnchors($page))->toContain('organizationsportal_linkscreate');
});

it('finds links and ignores the ones in code', function (): void {
    $prose = docsProse(implode("\n", [
        'See [roles](roles.md#who) and ![shot](../screenshots/a.png).',
        '`[not](a-link.md)`',
        '```',
        '[also not](b.md)',
        '```',
        '[ref]: ../index.md',
    ]));

    expect(docsLinkTargets($prose))->toBe(['roles.md#who', '../screenshots/a.png', '../index.md']);
});

it('refuses a link that climbs out of docs/', function (): void {
    expect(docsResolve('docs/guides', '../../resources/openapi/environment.yaml'))->toBe('resources/openapi/environment.yaml')
        ->and(docsResolve('docs', '../../elsewhere.md'))->toBeNull();
});
