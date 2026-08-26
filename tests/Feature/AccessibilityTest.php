<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

/**
 * Accessibility regression guard for the pages STILL SERVED BY VOLT: renders each one and
 * runs axe-core (WCAG 2.1 A/AA) over the HTML in jsdom via a tiny Node bridge.
 *
 * SHRINKING BY DESIGN. A client-rendered page has nothing in its response but a mount
 * point, so auditing it here would audit an empty document and report no violations —
 * exactly the shape of green {@see axeViolations()} guards against. Each page moves to
 * tests/Browser/AccessibilityTest.php as it is ported, where a real browser also computes
 * colour contrast, which jsdom cannot and which this bridge therefore disables. This file
 * goes when the last Volt page does.
 */
beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

/**
 * Run axe over an HTML string; returns the list of violations.
 *
 * A missing toolchain is a FAILURE, not a skip. This used to markTestSkipped, which
 * meant the entire guard could silently stop running — a green suite that asserted
 * nothing is worse than a red one that tells you to run `npm install`.
 *
 * @return array<int, array<string, mixed>>
 */
function axeViolations(string $html): array
{
    // A DOCUMENT, not a redirect body or an error page. axe finds no violations in an
    // empty document, so a sweep that never checked this reads as "accessible" for exactly
    // the pages that failed to render — which is how a guard covering a third of the
    // product came to look like coverage of all of it.
    expect(strlen($html))->toBeGreaterThan(2000, 'the page rendered no document to audit');

    /*
     * AND NOT A MOUNT POINT EITHER — the same failure wearing the size check's clothes.
     *
     * A ported page's response is `<div id="app" data-page="{…}">`, whose serialised props
     * sail past the length floor above while containing no markup at all. Five environment
     * pages sat in these datasets that way as they ported, reporting clean audits over a
     * document with one element in it.
     *
     * So it is refused here rather than in each caller: every dataset in this file inherits
     * the check, and the next plane to port cannot lose its coverage quietly. The page moves
     * to tests/Browser/AccessibilityTest, where a real browser renders it — and computes the
     * colour contrast jsdom cannot.
     */
    // ONE NEEDLE, NO MESSAGE. Pest's `toContain` is VARIADIC: a second argument is read as
    // a second needle, and the negated form then asks "contains neither" — which a page
    // containing the first happily satisfies. This check passed over three ported pages
    // while it carried an explanatory message. If it fails, the page is client-rendered and
    // belongs in tests/Browser/AccessibilityTest, where axe can see it.
    expect($html)->not->toContain('data-page=');

    foreach (['axe-core', 'jsdom'] as $pkg) {
        expect(file_exists(base_path("node_modules/{$pkg}")))
            ->toBeTrue("node_modules/{$pkg} is missing — run `npm install`. This guard must never be skipped.");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'a11y').'.html';
    file_put_contents($tmp, $html);

    $result = Process::path(base_path())->timeout(60)->run(['node', 'tests/a11y/axe-run.cjs', $tmp]);
    @unlink($tmp);

    expect($result->successful())->toBeTrue('axe bridge failed: '.$result->errorOutput());

    return json_decode($result->output(), true) ?: [];
}

/*
 * THE PUBLIC AUTH PAGES AND EVERY PORTED CONSOLE PAGE ARE AUDITED IN A REAL BROWSER, in
 * tests/Browser/AccessibilityTest.
 *
 * They are client-rendered, so there is nothing in the response here but a mount point —
 * auditing it would audit an empty document and report no violations, which is exactly
 * the shape of green {@see axeViolations()} exists to refuse. The browser sweep also
 * computes colour contrast, which jsdom cannot and which the bridge below disables.
 *
 * The console pages below follow as they are ported, and this file goes with the last of
 * them.
 */

/*
 * AND THE LAST ONE HAS GONE TOO.
 *
 * `/account` was the final entry here. Its audit — with rows on every list, in both themes
 * and at a phone width — is in tests/Browser/AccessibilityTest, where a real browser
 * renders the page and computes the colour contrast jsdom cannot.
 *
 * What is LEFT in this file is not a page sweep at all: the token-contrast checks below
 * compute WCAG ratios straight from the oklch design tokens, which is stronger than
 * auditing a page that happens to use them — they fail the moment somebody edits a token,
 * whether or not any page under test carries it. Those outlive the port.
 */

/*
|--------------------------------------------------------------------------
| Colour contrast (SC 1.4.3 / 1.4.11)
|--------------------------------------------------------------------------
| jsdom has no layout engine, so axe cannot compute contrast. These compute it
| directly from the oklch design tokens instead, which is stronger: it fails the
| moment someone edits a token, not only when a page happens to be under test.
*/

/** Convert oklch(L C H) to sRGB channels in 0..1 (gamma-encoded, clipped). */
function oklchToRgb(float $l, float $c, float $h): array
{
    $hr = deg2rad($h);
    $a = $c * cos($hr);
    $b = $c * sin($hr);

    $lp = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $mp = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $sp = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

    $lin = [
        4.0767416621 * $lp - 3.3077115913 * $mp + 0.2309699292 * $sp,
        -1.2684380046 * $lp + 2.6097574011 * $mp - 0.3413193965 * $sp,
        -0.0041960863 * $lp - 0.7034186147 * $mp + 1.7076147010 * $sp,
    ];

    return array_map(static function (float $x): float {
        $x = max($x, 0.0);
        $enc = $x <= 0.0031308 ? 12.92 * $x : 1.055 * $x ** (1 / 2.4) - 0.055;

        return min(1.0, max(0.0, $enc));
    }, $lin);
}

function relativeLuminance(array $rgb): float
{
    $f = static fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

    return 0.2126 * $f($rgb[0]) + 0.7152 * $f($rgb[1]) + 0.0722 * $f($rgb[2]);
}

function contrastRatio(array $a, array $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Alpha-composite a translucent token over an opaque backdrop, as a browser does. */
function compositeOver(array $fg, float $alpha, array $bg): array
{
    return [
        $fg[0] * $alpha + $bg[0] * (1 - $alpha),
        $fg[1] * $alpha + $bg[1] * (1 - $alpha),
        $fg[2] * $alpha + $bg[2] * (1 - $alpha),
    ];
}

/**
 * Parse the oklch custom properties out of one theme block of resources/css/app.css.
 *
 * @return array<string, array{l: float, c: float, h: float, a: float}>
 */
function themeTokens(string $theme): array
{
    $css = file_get_contents(base_path('resources/css/app.css'));

    // The light theme is the first `:root {` block; dark is the explicit opt-in block
    // (identical to the prefers-color-scheme copy above it).
    $needle = $theme === 'dark' ? ":root[data-theme='dark'] {" : ':root {';
    $start = strpos($css, $needle);
    expect($start)->not->toBeFalse("could not find the {$theme} token block in app.css");

    $block = substr($css, $start, strpos($css, "\n}", $start) - $start);

    preg_match_all(
        '/--([a-z0-9-]+):\s*oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*(?:\/\s*([\d.]+)\s*)?\)/i',
        $block,
        $m,
        PREG_SET_ORDER
    );

    $tokens = [];
    foreach ($m as $t) {
        $tokens[$t[1]] = [
            'l' => (float) $t[2],
            'c' => (float) $t[3],
            'h' => (float) $t[4],
            'a' => isset($t[5]) && $t[5] !== '' ? (float) $t[5] : 1.0,
        ];
    }

    return $tokens;
}

function tokenRgb(array $tokens, string $name): array
{
    expect($tokens)->toHaveKey($name);

    return oklchToRgb($tokens[$name]['l'], $tokens[$name]['c'], $tokens[$name]['h']);
}

it('renders every status colour used as TEXT at WCAG AA in both themes', function (string $theme): void {
    $t = themeTokens($theme);

    foreach (['success', 'warning', 'info', 'destructive'] as $base) {
        $strong = tokenRgb($t, "{$base}-strong");

        foreach (['card', 'background'] as $surface) {
            $bg = tokenRgb($t, $surface);
            $soft = compositeOver(tokenRgb($t, $base), $t["{$base}-soft"]['a'], $bg);

            // On the plain surface (danger-zone headings, "copy this now" callouts).
            expect(contrastRatio($strong, $bg))->toBeGreaterThanOrEqual(
                4.5,
                "--{$base}-strong on --{$surface} ({$theme})"
            );

            // On its own soft wash (pills, badges, toasts, stat-tile glyphs).
            expect(contrastRatio($strong, $soft))->toBeGreaterThanOrEqual(
                4.5,
                "--{$base}-strong on --{$base}-soft over --{$surface} ({$theme})"
            );
        }
    }
})->with(['light', 'dark']);

it('renders selected text at WCAG AA in both themes', function (string $theme): void {
    // ::selection paints --accent-foreground on --accent. In dark this was a near-white
    // on a light blue at 3.19:1; --accent-foreground now flips the way --primary-foreground
    // already did. The same pair is the avatar monogram in mobile-nav / theme-editor.
    $t = themeTokens($theme);

    expect(contrastRatio(tokenRgb($t, 'accent-foreground'), tokenRgb($t, 'accent')))
        ->toBeGreaterThanOrEqual(4.5, "--accent-foreground on --accent ({$theme})");
})->with(['light', 'dark']);

/*
|--------------------------------------------------------------------------
| Static markup guards
|--------------------------------------------------------------------------
*/

/**
 * Every Blade view this application renders, keyed by a readable path.
 *
 * INCLUDING THE MODULES. It walked `resources/views` alone, so seven in-tree modules —
 * compliance, billing, devices, analytics, connectors, risk-plus, whitelabel — were
 * outside every static guard in this file. Two compliance pages shipped a documented AA
 * contrast failure underneath a rule this same file states, and nothing said a word.
 *
 * The modules are not third-party code we happen to render: they are this product, split
 * across directories for release reasons. A guard that stops at one directory is a guard
 * whose coverage is an accident of layout.
 *
 * @return array<string, string> relative path => contents
 */
/**
 * Every React page, as source.
 *
 * @return array<string, string> repo-relative path => contents
 */
/**
 * Everywhere in React a search filter can live: the pages, and the chrome drawn on all of
 * them at once.
 *
 * The acting-organization picker is the reason this is not just `tsxPages()`. It is a
 * search over every tenant in the environment, it sits in the topbar rather than on a page,
 * and when it ported out of blade it left both populations at once — which the cross-walk
 * below caught as a page that had simply disappeared.
 *
 * @return array<string, string> repo-relative path => contents
 */
function searchableReactSources(): array
{
    return tsxPages() + tsxSourcesUnder(base_path('resources/js/chrome'));
}

function tsxPages(): array
{
    return tsxSourcesUnder(base_path('resources/js/pages'));
}

/**
 * Every React file under one directory, as source.
 *
 * Separate from {@see tsxPages()} because two different questions are asked of these
 * files. "Does every PAGE have a landmark" is about pages; "does every search filter
 * announce its result count" is about wherever a search filter IS — and one of them is in
 * the CHROME, which is drawn on every page at once. A sweep that only knew about pages lost
 * that control the moment it moved there.
 *
 * @return array<string, string> repo-relative path => contents
 */
function tsxSourcesUnder(string $root): array
{
    $out = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if ($source !== false) {
            $out[str_replace(base_path().'/', '', $file->getPathname())] = $source;
        }
    }

    return $out;
}

function bladeViews(?string $under = null): array
{
    $roots = [base_path('resources/views'.($under !== null ? '/'.$under : ''))];

    // The per-module view trees, only when the caller wants everything — a call narrowed
    // to `livewire/console` is asking about one plane and must not be widened.
    if ($under === null) {
        foreach ((array) glob(base_path('modules/*/resources/views')) as $moduleViews) {
            if (is_string($moduleViews) && is_dir($moduleViews)) {
                $roots[] = $moduleViews;
            }
        }
    }

    $out = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
    }

    return $out;
}

it('never uses a base status token as text colour', function (): void {
    // --success / --warning are tuned as FILLS: as text they measure 3.39:1 and 2.54:1
    // on --card. The *-strong siblings exist for text. --destructive clears AA on --card
    // but not on --destructive-soft, so it is only allowed off a soft wash.
    $offenders = [];

    foreach (bladeViews() as $path => $src) {
        foreach (['success', 'warning', 'warn'] as $token) {
            if (preg_match('/color:\s*var\(--'.$token.'\)/', $src)) {
                $offenders[] = "{$path}: color:var(--{$token}) — use --{$token}-strong";
            }
        }

        if (preg_match('/(danger|destructive)-soft\s*\)\s*;\s*color:\s*var\(--(danger|destructive)\)/', $src)) {
            $offenders[] = "{$path}: --destructive on its own soft wash is 3.94:1 — use --destructive-strong";
        }
    }

    expect($offenders)->toBe([]);
});

it('never uses a Tailwind dark: utility or a raw Tailwind palette class', function (): void {
    // The console is themed entirely through host CSS variables; a `dark:` utility or a
    // `text-gray-500` renders off-brand because the theme switch never reaches it.
    $offenders = [];

    foreach (bladeViews() as $path => $src) {
        if (preg_match('/\bdark:[a-z-]/', $src)) {
            $offenders[] = "{$path}: Tailwind dark: utility";
        }

        $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
        if (preg_match('/\b(?:text|bg|border|ring|from|to|via)-(?:'.$palette.')-\d{2,3}\b/', $src)) {
            $offenders[] = "{$path}: raw Tailwind palette class";
        }
    }

    expect($offenders)->toBe([]);
});

it('labels every search filter and announces its result count', function (): void {
    // WCAG 3.3.2 + 4.1.2: these were placeholder-only. SC 4.1.3: the list morphs in on a
    // debounced keystroke, so the count is the only thing that can report "no matches".
    $missingLabel = [];
    $missingStatus = [];

    // Both directories: a merged capability lives under `livewire/console` and serves
    // BOTH planes, so a sweep that only looked at `livewire/environment` would stop
    // covering a page the moment it was unified — silently losing coverage exactly where
    // the unification could have broken something.
    $searchViews = array_filter(
        bladeViews('livewire/environment') + bladeViews('livewire/console'),
        static fn (string $src): bool => str_contains($src, 'wire:model.live.debounce.300ms="search"'),
    );

    foreach ($searchViews as $path => $src) {

        preg_match('/<input[^>]*wire:model\.live\.debounce\.300ms="search"[^>]*>/', $src, $m);

        if ($m === [] || ! str_contains($m[0], 'aria-label=')) {
            $missingLabel[] = $path;
        }

        if (! preg_match('/role="status"[^>]*aria-live="polite"/', $src)) {
            $missingStatus[] = $path;
        }
    }

    expect($missingLabel)->toBe([]);
    expect($missingStatus)->toBe([]);

    /*
     * GUARD THE SWEEP, ACROSS BOTH STACKS.
     *
     * This used to be a bare count of Volt views, which meant it fell by one every time a
     * searchable list was ported — and a falling number that has to be edited downwards is
     * a guard that gets edited rather than read. Worse, the edit and a genuinely deleted
     * announcement look identical.
     *
     * So it is a CROSS-WALK instead: how many searchable list pages this console has, in
     * total, over the two stacks it currently spans. Porting one moves a page from the
     * left side to the right and leaves the total alone; DELETING one moves the total, and
     * that is the thing worth being told about. It only ever grows.
     */
    $ported = count(array_filter(
        searchableReactSources(),
        static fn (string $source): bool => str_contains($source, 'type="search"'),
    ));

    expect(count($missingLabel) + count($missingStatus))->toBe(0)
        ->and(count($searchViews) + $ported)->toBeGreaterThanOrEqual(19);
});

/**
 * THE SAME PROMISE, ON THE PAGES THAT NO LONGER HAVE A BLADE.
 *
 * The sweep above counts Volt views, so it loses a page every time one is ported — and it
 * lost four of them silently before anybody noticed the announcement had gone with the
 * markup. Four ported lists filtered on a debounced keystroke with nothing left to say
 * "no matches": the count guard above went from 22 to 17 one page at a time, and each drop
 * looked like progress.
 *
 * A SOURCE SWEEP, and it says so. It cannot see whether the element is rendered — for that
 * see tests/Browser — but it is the same contract the blade sweep held, applied to the
 * files that replaced those blades, and it is what stops the next port dropping this again.
 *
 * `<output>` rather than `<p role="status">`: it carries the role implicitly and is the
 * element the platform already maps to it.
 */
/**
 * Whether the element at `$offset` sits inside a `<Field label=…>`.
 *
 * Containment rather than proximity: the nearest `<Field` BEFORE it must not have been
 * closed before it — otherwise a page with a labelled field further up would vouch for an
 * unlabelled box lower down, which is exactly the thing this is supposed to catch.
 */
function insideLabelledField(string $src, int $offset): bool
{
    $open = strrpos(substr($src, 0, $offset), '<Field');

    if ($open === false) {
        return false;
    }

    $closedBetween = strpos($src, '</Field>', $open);

    if ($closedBetween !== false && $closedBetween < $offset) {
        return false;
    }

    // The opening tag itself, up to its `>`, is where the label lives.
    $tag = substr($src, $open, (int) (strpos($src, '>', $open) ?: $open) - $open);

    return str_contains($tag, 'label=');
}

it('labels every ported search filter and announces its result count', function (): void {
    $missingLabel = [];
    $missingStatus = [];
    $searched = [];

    foreach (searchableReactSources() as $path => $src) {
        if (! str_contains($src, 'type="search"')) {
            continue;
        }

        $searched[] = $path;

        /*
         * Lazy to the first `/>`, and NOT `[^>]*` — a JSX attribute value contains `>`
         * every time it holds an arrow function, so a character class stopping at `>`
         * matched three characters of `onChange={(event) =` and reported every page as
         * unlabelled. `=>` is not `/>`, so this stops exactly where the element does.
         */
        preg_match_all('/<Input\b(.*?)\/>/s', $src, $inputs, PREG_OFFSET_CAPTURE);

        foreach ($inputs[0] as [$input, $offset]) {
            if (! str_contains($input, 'type="search"')) {
                continue;
            }

            /*
             * EITHER SPELLING COUNTS, because both produce a labelled control and one of
             * them produces a better one. A bare filter box carries `aria-label`; a box
             * inside a `<Field label=…>` gets a real `<label>` wired to it by id, which is
             * what a screen reader announces AND what a sighted person can click. This
             * used to demand `aria-label` alone and reported the properly-labelled page
             * as the broken one.
             */
            if (str_contains($input, 'aria-label=')) {
                continue;
            }

            if (! insideLabelledField($src, $offset)) {
                $missingLabel[] = $path;
            }
        }

        if (! str_contains($src, '<output')) {
            $missingStatus[] = $path;
        }
    }

    expect($missingLabel)->toBe([]);
    expect($missingStatus)->toBe([]);
    // Guard the sweep itself: a renamed idiom must not make this pass by matching nothing.
    expect($searched)->not->toBe([]);
});

it('never leaves an input labelled by placeholder alone', function (): void {
    $offenders = [];

    foreach (bladeViews() as $path => $src) {
        preg_match_all('/<input\b[^>]*placeholder="[^"]*"[^>]*>/s', $src, $m);

        foreach ($m[0] as $tag) {
            if (str_contains($tag, 'aria-label=') || str_contains($tag, 'aria-hidden=') || str_contains($tag, 'type="hidden"')) {
                continue;
            }

            // A control that is both unreachable and unfillable is decoration, not a form
            // field — the theme editor's sign-in mockup. Both attributes are required, and
            // the region around it carries aria-hidden.
            if (str_contains($tag, 'tabindex="-1"') && str_contains($tag, 'readonly')) {
                continue;
            }

            // Otherwise it must carry an id that a <label for> in the same file points at.
            if (preg_match('/\bid="([^"]+)"/', $tag, $id) && str_contains($src, 'for="'.$id[1].'"')) {
                continue;
            }

            // …or be wrapped by a <label> (the checkbox/radio idiom).
            if (preg_match('/<label\b[^>]*>(?:(?!<\/label>).)*'.preg_quote($tag, '/').'/s', $src)) {
                continue;
            }

            $offenders[] = $path.': '.mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 110);
        }
    }

    expect($offenders)->toBe([]);
});

it('guards every irreversible delete with type-to-confirm', function (): void {
    // The component names the resource AND the environment and cannot be completed by
    // muscle memory; a native wire:confirm named neither and Enter dismissed it.
    $offenders = [];

    foreach (bladeViews() as $path => $src) {
        preg_match_all('/<button\b[^>]*wire:confirm=[^>]*>/s', $src, $m);

        foreach ($m[0] as $tag) {
            if (preg_match('/wire:click="(delete|destroy|remove|purge)[A-Za-z]*(\(|")/i', $tag)
                && preg_match('/wire:confirm="[^"]*(cannot be undone|permanently)/i', $tag)) {
                $offenders[] = $path.': '.mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 110);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('gives every environment detail page a real h2 outline', function (): void {
    // 24 pages skipped h1 -> h3 because section headers were weighted <p>.
    $flat = [];

    // Both directories, for the same reason the search sweep above takes both: a merged
    // capability lives under `livewire/console` and still serves this plane, so looking
    // only at `livewire/environment` would drop a detail page out of coverage exactly
    // when its markup was being rewritten.
    foreach (bladeViews('livewire/environment') + bladeViews('livewire/console') as $path => $src) {
        if (! str_contains($src, '<h1')) {
            continue;
        }

        // A page with several bordered section panels must expose them as headings.
        $panels = preg_match_all('/<div class="rounded-xl border p-5"/', $src);

        if ($panels >= 2 && ! str_contains($src, '<h2')) {
            $flat[] = "{$path}: {$panels} section panels, no <h2>";
        }
    }

    expect($flat)->toBe([]);
});

/**
 * The page heading must not carry the help popover inside it.
 *
 * `<x-help>` was rendered as a CHILD of the `<h1>`, so the heading's accessible name
 * became "Members What is Members? Members are the people who…" — on every page that
 * passes `:help`. That heading is the primary landmark a screen-reader user navigates by,
 * and it was reading out a paragraph.
 */
it('keeps the help popover out of the page heading', function (): void {
    $header = (string) file_get_contents(base_path('resources/js/ui/PageHeader.tsx'));

    // The h1 must be a single element with nothing but the page's title in it. Nested, the
    // heading's accessible name became "Members What is Members? Members are the people
    // who…" — on the one landmark a screen-reader user navigates the page by.
    expect($header)->toMatch('/<h1 className="cbx-page-title">\{title \?\? stated\}<\/h1>/');

    $between = (string) preg_replace('/^.*<h1 className="cbx-page-title">|<\/h1>.*$/s', '', $header);

    // No message argument: `toContain` is variadic, so a second string is another needle
    // and `not->toContain` passes as soon as one is missing. The `toMatch` above carries
    // the real assertion, but a guard that cannot fail is worse than none.
    expect($between)->not->toContain('<Help');
});

/*
 * "IT DOES NOT CLAIM TO BE A DIALOG" STOOD HERE, and it has moved rather than gone.
 *
 * The blade help panel put `role="dialog"` on a div that took no focus, trapped nothing
 * and restored nothing, so a screen reader announced a dialog its user could neither enter
 * nor leave. This read the blade file for the string, which is all a source-grep can do.
 *
 * The port is a Radix popover, and a popover IS a non-modal dialog — it takes focus, closes
 * on Escape and hands focus back — so asserting the role's ABSENCE would now assert the
 * wrong thing. What is worth holding is the behaviour the old markup only claimed to have,
 * and that needs a rendered DOM: see `resources/js/ui/Help.test.tsx`, which opens the
 * popover, reaches its link, presses Escape and checks where focus landed. Run by
 * `npm run test`, which the CI workflow and the Phase-9 gate both call.
 */

/*
 * THE ENVIRONMENT CONSOLE IS AUDITED IN A REAL BROWSER, in tests/Browser.
 *
 * It had a sweep of its own here, and the sweep shrank one page at a time as that plane
 * ported — until the last of them went and it had nothing left to audit. It is deleted
 * rather than left pointing at whatever page happens still to be Volt: `axeViolations()`
 * refuses to audit a mount point, so the alternative was a guard that fails for a reason
 * that has nothing to do with accessibility.
 *
 * What replaced it is strictly better rather than merely equivalent. jsdom has no layout
 * engine and no cascade, so this bridge disables `color-contrast` — the single rule this
 * design system's tokens are most carefully tuned for, and the one that has actually
 * regressed here before. A real browser computes it.
 */

/*
 * THE OPERATOR CONSOLE'S OWN SWEEP IS GONE FROM HERE.
 *
 * All eight of its pages have ported, so every one of them answers this sweep with a mount
 * point — a document axe finds nothing wrong with because there is nothing in it. Keeping
 * the dataset would have been the exact failure `axeViolations()` refuses on: coverage that
 * reads as green while auditing one `<div>`.
 *
 * The coverage moved to tests/Browser/AccessibilityTest, which runs axe against the drawn
 * page and computes the colour contrast jsdom cannot — and it audits MORE than this did:
 * the two detail pages, and lists with rows on them rather than empty states.
 */

/*
 * SESSIONS & ACTIVITY has ported too, and its audit moved to tests/Browser for the same
 * reason as the operator plane's: this sweep would be auditing a mount point. It is the
 * page a person opens to find a session they do not recognise — exactly the kind that must
 * be readable by somebody who cannot see it — so it is audited with rows on every one of
 * its three lists, in both themes and at a phone width.
 */
