<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\EnvironmentSudo;
use App\Platform\Sudo;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Database\Seeders\DemoEnvironmentSeeder;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Tests\Support\DocsScreenshots;

/**
 * THE PICTURES IN THE DOCUMENTATION, TAKEN FROM THE PRODUCT.
 *
 * `docs/screenshots/*.png` used to be taken by hand, once, and then drifted: a page was
 * renamed, a rail grew a section, a list moved to the organization hub, and the picture kept
 * showing the console as it had been. This file takes them again, from the running app, over
 * a world with something in it ({@see DemoEnvironmentSeeder}: a workspace, its apps, three
 * customer organizations with SSO and Directory Sync, agents with approvals waiting, audit
 * events, a webhook and a log stream) — so regenerating them is one command rather than an
 * afternoon:
 *
 *   vendor/bin/pest --group=docs-screenshots
 *
 * AND NOBODY HAS TO REMEMBER TO RUN IT. `.github/workflows/docs-screenshots.yml` runs this
 * group on every release tag (and on demand), in the same Linux image and Chromium that CI
 * and the visual baselines use, and opens a pull request with whatever changed. It never
 * pushes to main: a picture is reviewed by eye in the PR's diff like any other change to
 * the docs.
 *
 * WHICH PICTURES: exactly {@see DocsScreenshots::NAMES}. A name not on that list is refused
 * here, and DocsScreenshotsCatalogueTest (normal suite) holds docs/ to the same list — every
 * file there is one this run takes, every one is embedded by a page, none is missing.
 *
 * IT IS ALSO A SMOKE TEST of every page the docs show. A picture of a 404, a 500, the
 * console's "Something went wrong" fallback, a sign-in redirect or a step-up screen saved as
 * "applications.png" is worse than no picture, so before any picture is saved
 * {@see assertDocsPageHealthy()} fails the run when the page's own response was an error,
 * when anything it loaded (a script, a font, an image, an Inertia request) came back 4xx/5xx,
 * when either error page is on screen, when the browser reported an uncaught exception, a
 * console error or warning, or a broken image — and each page is also checked for the text it
 * is named after. So the release workflow that regenerates the pictures fails, and opens no
 * PR, when a page the documentation shows is broken.
 *
 * Excluded from every normal run all the same: phpunit.xml excludes the group, and so does
 * CI's own `--exclude-group` (a CLI exclusion REPLACES the XML one, so the workflow has to
 * name it too), because it writes into the working tree. It is not a visual regression
 * suite; that is VisualRegressionTest, which compares against baselines drawn on Linux.
 *
 * THE SHAPE IS THE SAAS ONE. The pages a reader of the docs needs — Applications, the
 * organization hub, Agents, Approvals, the audit logs, webhooks and log streams — are the
 * ENVIRONMENT console's, under `/admin` on the environment's own host, which exists only on
 * a multi-tenant deployment. The workspace's Projects page lives on the account host, so it is
 * visited with that host on the request (`withHost()`), as a browser on cboxid.com would.
 *
 * LIGHT THEME, 1440×900 (the MacBook Air preset: no touch, a desktop layout), viewport only —
 * a full-page capture of a long list makes a picture nobody can read at the width the docs
 * render it. Saved at CSS-pixel scale, so a file is 1440 pixels wide.
 *
 * Dates on the pages are real and relative ("2 minutes ago"), which is why these are pictures
 * for people and never baselines for a diff.
 *
 * BUILD THE FRONTEND FIRST (`npm run build`). The browser loads whatever is in public/build;
 * assets built from another checkout render this one's props wrong, and the console's error
 * boundary is what gets photographed — which the health check now refuses to save.
 */
/** When this file was loaded — before any picture was taken — for the last test's staleness check. */
define('DOCS_SCREENSHOTS_STARTED_AT', time());

afterEach(function (): void {
    // Static, so it would outlive this file and put every later browser test on the demo host.
    Playwright::setHost(null);
});

/** Where the pictures go. */
function docsScreenshotPath(string $name): string
{
    return base_path('docs/screenshots/'.$name.'.png');
}

/**
 * Open a page the way every picture here is taken: light, 1440×900, optionally on another host.
 */
function docsPage(string $path, ?string $host = null): PendingAwaitablePage
{
    $page = visit($path)->on()->macbookAir()->inLightMode();

    return $host === null ? $page : $page->withHost($host);
}

/**
 * Save what the page shows now as `docs/screenshots/{name}.png` — once the page has proved
 * it is the page ({@see assertDocsPageHealthy()}).
 *
 * The plugin writes into tests/Browser/Screenshots (gitignored, and emptied between runs);
 * the picture is moved from there to where the docs reference it. Fonts first: a capture
 * taken before the web font arrives is drawn in the fallback face, which is the one thing
 * that makes a screenshot look broken without anything being wrong.
 */
function saveDocsScreenshot(AwaitableWebpage|PendingAwaitablePage $page, string $name): void
{
    expect(in_array($name, DocsScreenshots::NAMES, true))->toBeTrue("[{$name}] is not in Tests\\Support\\DocsScreenshots::NAMES — add it there, embed it in a docs page and list it in docs/screenshots/_index.md.");

    assertDocsPageHealthy($page, $name);

    $page->script('document.fonts.ready.then(() => true)');
    $page->screenshot(false, 'docs-screenshot--'.$name);

    $taken = base_path('tests/Browser/Screenshots/docs-screenshot--'.$name.'.png');

    expect(is_file($taken))->toBeTrue("The browser saved no picture for [{$name}].");

    rename($taken, docsScreenshotPath($name));
}

/**
 * Fail unless the page on screen rendered without an error of any kind.
 *
 * What a person would notice, checked by the browser rather than by eye:
 *
 *  - THE DOCUMENT'S OWN STATUS, from the Navigation Timing entry. Every error view renders a
 *    perfectly ordinary-looking page, so "it has text on it" proves nothing; the response
 *    code does.
 *  - EVERY SUBRESOURCE: a script, stylesheet, font or image, and every Inertia or fetch
 *    request the page made, by its Resource Timing status. A 404 on a chunk or a 500 on
 *    a partial reload is a broken page that still draws.
 *  - EITHER ERROR PAGE on screen: the server's (resources/views/errors, which carries a
 *    `data-error-reload` button) and the React root's fallback
 *    (AppErrorBoundary's `data-app-error`).
 *  - WHAT THE BROWSER LOGGED, read from Playwright's own per-page buffers (the
 *    `consoleMessages` and `pageErrors` protocol calls), which hold everything since the
 *    page opened — including what happened before any script of ours could listen, which
 *    the plugin's own init-script hook cannot see beyond `console.log` and `window.onerror`.
 *    An uncaught exception, an unhandled rejection, a `console.error` or a `console.warn`,
 *    or Chromium's own "Failed to load resource" line, fails it.
 *  - BROKEN IMAGES, and the plugin's own JavaScript-error and console-log captures.
 */
function assertDocsPageHealthy(AwaitableWebpage|PendingAwaitablePage $page, string $name): void
{
    /** @var array{url: string, status: int, failed: list<string>, serverErrorPage: bool, clientErrorPage: bool} $health */
    $health = $page->script(<<<'JS'
        () => {
            const navigation = performance.getEntriesByType('navigation')[0];

            return {
                url: location.href,
                status: navigation ? navigation.responseStatus : 0,
                failed: performance.getEntriesByType('resource')
                    .filter((entry) => entry.responseStatus >= 400)
                    .map((entry) => entry.responseStatus + ' ' + entry.name),
                serverErrorPage: document.querySelector('[data-error-reload]') !== null,
                clientErrorPage: document.querySelector('[data-app-error]') !== null,
            };
        }
        JS);

    $where = "[{$name}] at {$health['url']}";

    expect($health['status'])->toBeGreaterThanOrEqual(200, "{$where}: the page reported no response status.")
        ->and($health['status'])->toBeLessThan(400, "{$where}: the page itself answered HTTP {$health['status']}.")
        ->and($health['failed'])->toBe([], "{$where}: requests the page made failed.")
        ->and($health['serverErrorPage'])->toBeFalse("{$where}: the server's error page is on screen.")
        ->and($health['clientErrorPage'])->toBeFalse("{$where}: the console's \"Something went wrong\" fallback is on screen.")
        ->and(docsBrowserProblems($page->page()))->toBe([], "{$where}: the browser reported errors.");

    $page->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoBrokenImages();
}

/**
 * Every console error or warning and every uncaught exception the browser recorded for
 * $page since it opened, from Playwright's server-side buffers.
 *
 * @return list<string>
 */
function docsBrowserProblems(Page $page): array
{
    // The plugin keeps the page's protocol id private; the two calls below are plain
    // Playwright protocol (Page.consoleMessages / Page.pageErrors, Playwright 1.56+).
    $guid = (fn (): string => $this->guid)->call($page);
    $problems = [];

    foreach (Client::instance()->execute($guid, 'consoleMessages', ['filter' => 'all']) as $message) {
        /** @var array{result?: array{messages?: list<array{type: string, text: string, location?: array{url?: string}}>}} $message */
        foreach ($message['result']['messages'] ?? [] as $console) {
            if (in_array($console['type'], ['error', 'warning'], true)) {
                $problems[] = 'console.'.$console['type'].': '.$console['text'].(isset($console['location']['url']) && $console['location']['url'] !== '' ? ' ('.$console['location']['url'].')' : '');
            }
        }
    }

    foreach (Client::instance()->execute($guid, 'pageErrors', ['filter' => 'all']) as $message) {
        /** @var array{result?: array{errors?: list<array{error?: array{message?: string, name?: string}, value?: mixed}>}} $message */
        foreach ($message['result']['errors'] ?? [] as $error) {
            $problems[] = 'uncaught: '.($error['error']['message'] ?? (string) json_encode($error));
        }
    }

    return $problems;
}

/** The host the demo environment answers on, as its customers would see it. */
const DOCS_ENVIRONMENT_HOST = 'id.ledger.example';

/**
 * The demo world, with its environment answering as `id.ledger.example`.
 *
 * THE HOST IS STATED THREE TIMES, because the pictures show it. The environment's custom
 * domain is what its issuer, its SCIM base URL and its snippets print; the request's Host is
 * what resolves the environment ({@see Playwright::setHost()} puts it on every request the
 * browser makes, which still connects to this server's 127.0.0.1 port); and the URL
 * generator's origin is what every absolute link on the page is built from. Without the
 * three agreeing, "Connect it" reads `issuer: 'https://127.0.0.1'` — true of this run and of
 * nobody's deployment. Links built that way point at a host the browser cannot reach, which
 * is fine for a picture of the first render and is why nothing here navigates by clicking
 * after the first load, apart from the Admin Portal (which keeps the server's own origin).
 */
function docsWorld(bool $onDemoHost = true): DemoEnvironmentSeeder
{
    multiTenantDeployment();

    $seeder = new DemoEnvironmentSeeder;
    $seeder->setContainer(app());
    $seeder->run();

    $environment = $seeder->environment;
    expect($environment)->not->toBeNull();
    assert($environment !== null);

    if ($onDemoHost) {
        $environment->forceFill(['domain' => DOCS_ENVIRONMENT_HOST, 'domain_verified_at' => now()])->save();
        Playwright::setHost(DOCS_ENVIRONMENT_HOST);
        app('url')->useOrigin('https://'.DOCS_ENVIRONMENT_HOST);
        app('url')->forceScheme('https');
    } else {
        serveOnTestHost($environment);
    }

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    return $seeder;
}

/** Sign Ada in to the environment console, past the step-up some pages sit behind. */
function signInAsDemoOwner(DemoEnvironmentSeeder $demo): void
{
    $environment = $demo->environment;
    assert($environment !== null);

    actAsEnvironmentAdmin($demo->ownerId, $environment->id);
    app(EnvironmentSudo::class)->confirm();
    app(Sudo::class)->confirm();
}

it('pictures the hosted sign-in page and the workspace sign-up', function (): void {
    docsWorld();

    // The environment's own door, which its end users meet under its name.
    $page = docsPage('/login')->assertSee('Sign in');

    saveDocsScreenshot($page, 'hosted-sign-in');

    // Cbox ID's own door on the account host, where a new customer creates a workspace.
    $page = docsPage('/signup', 'cboxid.com')->assertSee('Create');

    saveDocsScreenshot($page, 'workspace-sign-up');
})->group('docs-screenshots');

it('pictures the environment console', function (): void {
    $demo = docsWorld();
    signInAsDemoOwner($demo);

    $acme = $demo->organizations['acme']->id;
    $globex = $demo->organizations['globex']->id;

    $pages = [
        'environment-overview' => ['/admin', 'Overview'],
        'get-started' => ['/admin/get-started', 'Ledger'],
        'applications' => ['/admin/apps', 'Ledger Web'],
        'application-detail' => ['/admin/apps/'.$demo->apps['Ledger Web'], 'Ledger Web'],
        'api-keys' => ['/admin/keys/frontend', 'Local development'],
        'organizations' => ['/admin/organizations', 'Acme Corp'],
        'organization-overview' => ['/admin/organizations/'.$acme, 'Acme Corp'],
        'organization-members' => ['/admin/organizations/'.$acme.'/members', 'Wile E. Coyote'],
        'organization-enterprise-sso' => ['/admin/organizations/'.$acme.'/single-sign-on', 'Okta'],
        'organization-domains' => ['/admin/organizations/'.$acme.'/domains', 'acme.example'],
        'organization-directory-sync' => ['/admin/organizations/'.$globex.'/directory-sync', 'Okta SCIM'],
        'directory-detail' => ['/admin/sync-in/'.$demo->directoryId, 'Engineering'],
        'agents' => ['/admin/agents', 'Claude Code'],
        'approvals' => ['/admin/approvals', 'Claude Code'],
        'audit-log' => ['/admin/audit', 'Audit log'],
        'app-audit-logs' => ['/admin/audit-logs', 'invoice.voided'],
        'webhooks' => ['/admin/webhooks', 'hooks.ledger.example'],
        'log-streams' => ['/admin/log-streaming', 'Splunk (security team)'],
        'users' => ['/admin/users', 'Wile E. Coyote'],
        'roles' => ['/admin/roles', 'Accountant'],
        'environment-settings' => ['/admin/settings', 'Settings'],
    ];

    foreach ($pages as $name => [$path, $expect]) {
        $page = docsPage($path)->assertSee($expect);

        saveDocsScreenshot($page, $name);
    }

    // The context switcher, open on the environment: a popover drawn by the client, so the
    // click navigates nowhere and is safe on the demo host.
    $page = docsPage('/admin')
        ->assertSee('Overview')
        ->click('nav.cbx-ctx .cbx-ctx-trigger >> nth=-1')
        ->assertSee('Ledger Sandbox')
        ->wait(0.5);

    saveDocsScreenshot($page, 'context-switcher');

    // A phone. `resize()` rather than the device preset, which emulates touch — see
    // ConsoleMobileTest for why that matters to this harness.
    $page = docsPage('/admin')
        ->assertSee('Overview')
        ->resize(375, 812)
        ->wait(0.5);

    saveDocsScreenshot($page, 'mobile-overview');
})->group('docs-screenshots');

it('pictures the workspace\'s projects on the account host', function (): void {
    $demo = docsWorld();
    signInAsDemoOwner($demo);

    $page = docsPage('/projects', 'cboxid.com')
        ->assertSee('Ledger')
        ->assertSee('Analytical Engine');

    saveDocsScreenshot($page, 'workspace-projects');
})->group('docs-screenshots');

it('pictures the Admin Portal as the customer\'s IT administrator opens it', function (): void {
    // On the server's own host: opening the portal is a click, and a click must reach it.
    $demo = docsWorld(onDemoHost: false);

    $token = app(AdminPortal::class)->generate(
        $demo->organizations['globex']->id,
        PortalScope::of([PortalIntent::Sso, PortalIntent::Dsync, PortalIntent::DomainVerification, PortalIntent::AuditLogs, PortalIntent::LogStreams]),
        $demo->ownerId,
    );

    $page = docsPage('/setup/'.$token)->assertSee('Open setup');

    saveDocsScreenshot($page, 'admin-portal-link');

    $page->click('button:has-text("Open setup")')
        ->assertSee('Set up Globex')
        // Inertia's progress bar outlives the text it navigated to by a moment.
        ->wait(1);

    saveDocsScreenshot($page, 'admin-portal');
})->group('docs-screenshots');

/*
 * The last word: every picture on the list was written by THIS run. A name added to
 * DocsScreenshots::NAMES that no test above takes would otherwise sit in docs/screenshots
 * as whatever was there before — the stale picture this file exists to prevent.
 */
it('took every picture the documentation embeds', function (): void {
    $stale = array_values(array_filter(
        DocsScreenshots::NAMES,
        static fn (string $name): bool => ! is_file(docsScreenshotPath($name)) || filemtime(docsScreenshotPath($name)) < DOCS_SCREENSHOTS_STARTED_AT,
    ));

    expect($stale)->toBe([], 'Not taken by this run: '.implode(', ', $stale).'.');
})->group('docs-screenshots');
