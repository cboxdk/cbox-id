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
use Pest\Browser\Playwright\Playwright;

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
 * NOT A TEST OF ANYTHING, and excluded from every normal run for that reason: phpunit.xml
 * excludes the group, and so does CI's own `--exclude-group` (a CLI exclusion REPLACES the
 * XML one, so the workflow has to name it too). The assertions below only make sure each
 * picture is of the page it is named after — a picture of a 404, a sign-in redirect or a
 * step-up screen saved as "applications.png" is worse than no picture. It is also not a
 * visual regression suite; that is VisualRegressionTest, which compares against baselines
 * drawn on Linux. These are written over whatever is in `docs/screenshots` and reviewed by
 * eye in the diff, like any other change to the docs.
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
 * boundary is what gets photographed.
 */
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
 * Save what the page shows now as `docs/screenshots/{name}.png`.
 *
 * The plugin writes into tests/Browser/Screenshots (gitignored, and emptied between runs);
 * the picture is moved from there to where the docs reference it. Fonts first: a capture
 * taken before the web font arrives is drawn in the fallback face, which is the one thing
 * that makes a screenshot look broken without anything being wrong.
 */
function saveDocsScreenshot(AwaitableWebpage|PendingAwaitablePage $page, string $name): void
{
    $page->script('document.fonts.ready.then(() => true)');
    $page->screenshot(false, 'docs-screenshot--'.$name);

    $taken = base_path('tests/Browser/Screenshots/docs-screenshot--'.$name.'.png');

    expect(is_file($taken))->toBeTrue("The browser saved no picture for [{$name}].");

    rename($taken, docsScreenshotPath($name));
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
        $page = docsPage($path)
            ->assertSee($expect)
            ->assertNoJavaScriptErrors();

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
        ->assertSee('Analytical Engine')
        ->assertNoJavaScriptErrors();

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
        ->assertNoJavaScriptErrors()
        // Inertia's progress bar outlives the text it navigated to by a moment.
        ->wait(1);

    saveDocsScreenshot($page, 'admin-portal');
})->group('docs-screenshots');
