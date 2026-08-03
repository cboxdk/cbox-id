<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Accessibility regression guard: renders each key page and runs axe-core
 * (WCAG 2.1 A/AA) over the HTML in jsdom via a tiny Node bridge. A new unlabelled
 * control, missing form label, or broken ARIA fails the suite. Requires Node
 * (already needed for Vite); colour contrast is computed here from the tokens.
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

it('has no WCAG 2.1 A/AA violations on the public auth pages', function (string $path): void {
    if ($path === '__reset__') {
        app(Subjects::class)->create('reset@acme.test', 'R', 'super-secret-1234');
        $path = '/reset-password/'.app(PasswordReset::class)->request('reset@acme.test');
    }

    $html = $this->get($path)->assertOk()->getContent();

    expect(axeViolations($html))->toBe([]);
})->with([
    'login' => '/login',
    'signup' => '/signup',
    'forgot-password' => '/forgot-password',
    'reset-password' => '__reset__',
]);

/**
 * The hosted surface had no landmarks at all: its layout carried neither a <main> nor a
 * skip link, while every other layout in the repo carries both. That covers sign-in,
 * MFA, passkeys, consent and device approval — the pages a tenant's own users see, and
 * the ones this platform is judged on.
 */
it('gives the hosted surface a main landmark and a skip link', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain('id="main-content"')
        ->and($html)->toContain('href="#main-content"')
        ->and(substr_count((string) $html, '<main'))->toBe(1);
})->with([
    'login' => '/login',
    'signup' => '/signup',
    'forgot-password' => '/forgot-password',
]);

it('has no WCAG 2.1 A/AA violations on the console pages', function (string $path): void {
    $subject = app(Subjects::class)->create('a11y@acme.test', 'A11y Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    // A magic-link redemption establishes the platform session for later requests.
    $this->get('/magic/'.app(MagicLink::class)->request('a11y@acme.test'));

    $html = $this->get($path)->assertOk()->getContent();

    expect(axeViolations($html))->toBe([]);
})->with([
    'dashboard' => '/dashboard',
    'get-started' => '/get-started',
    'usage' => '/usage',
    'approvals' => '/approvals',
    'members' => '/members',
    'roles' => '/roles',
    'connections' => '/connections',
    'directories' => '/directories',
    'provisioning' => '/provisioning',
    'governance' => '/governance',
    'sod-policies' => '/sod-policies',
    'clients' => '/clients',
    'webhooks' => '/webhooks',
    'hooks' => '/hooks',
    'audit' => '/audit',
    'settings' => '/settings',
    'appearance' => '/appearance',
    'account' => '/account',
]);

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

/** @return array<string, string> relative path => contents */
function bladeViews(?string $under = null): array
{
    $root = base_path('resources/views'.($under !== null ? '/'.$under : ''));
    $out = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $out[str_replace(base_path('resources/views').'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
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
    $searchViews = bladeViews('livewire/environment') + bladeViews('livewire/console');

    foreach ($searchViews as $path => $src) {
        if (! str_contains($src, 'wire:model.live.debounce.300ms="search"')) {
            continue;
        }

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
    // Guard the sweep itself: if the search idiom is renamed, this must not silently pass.
    expect(count(array_filter(
        $searchViews,
        static fn (string $s): bool => str_contains($s, 'wire:model.live.debounce.300ms="search"')
    )))->toBe(15);
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

it('gives the identifier-first password step focus and an announcement', function (string $view): void {
    // HTML autofocus only fires at document parse, and Livewire morphs this step in —
    // focus stayed on <body> and all three live regions were empty.
    $src = file_get_contents(base_path("resources/views/livewire/{$view}.blade.php"));

    expect($src)->toContain('x-init="$el.focus()"');
    expect($src)->toMatch('/role="status" aria-live="polite"[^>]*>\s*@if \(\$identified\)/');
    // The password input must NOT rely on autofocus, which is the bug being fixed.
    expect($src)->toMatch('/<input[^>]*type="password"(?:(?!>)[\s\S])*?>/');
    preg_match('/<input[^>]*type="password"(?:(?!>)[\s\S])*?>/', $src, $pw);
    expect($pw[0])->not->toContain('autofocus');
})->with(['auth/login', 'workspace/login']);

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
    $header = (string) file_get_contents(base_path('resources/views/components/page-header.blade.php'));

    // The h1 must be a single line with nothing but the title in it.
    expect($header)->toMatch('/<h1 class="cbx-page-title">\{\{ \$title \}\}<\/h1>/');

    $between = (string) preg_replace('/^.*<h1 class="cbx-page-title">|<\/h1>.*$/s', '', $header);

    expect($between)->not->toContain('x-help', 'the help control is nested inside the page heading');
});

/**
 * And the popover is a disclosure, not a dialog: it never takes focus, traps nothing and
 * restores nothing, so `role="dialog"` made screen readers announce and manage it as
 * something it is not.
 */
it('does not claim to be a dialog', function (): void {
    $help = (string) file_get_contents(base_path('resources/views/components/help.blade.php'));

    // Strip Blade comments first: the docblock explaining WHY the role was removed
    // contains the string, and a check that cannot tell markup from prose would fail on
    // its own explanation.
    $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $help);

    expect($markup)->not->toContain('role="dialog"')
        // The button-to-panel relationship that IS correct must still be there.
        ->and($markup)->toContain('aria-controls')
        ->and($markup)->toContain('aria-expanded');
});
