<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Database\Seeders\DemoEnvironmentSeeder;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

/**
 * THE SHARED SHELL IN A REAL BROWSER (cbox-ui 0.11, laravel-console-kit 0.2.6): the phone at
 * 390×844 and the desktop at 1440×900, each switching where you work.
 *
 * `resize()` rather than a device preset: the preset emulates touch and a click never
 * completes in this harness (see ConsoleMobileTest).
 *
 * Every step is also saved as a picture in tests/Browser/Screenshots (`shell-…`), which is
 * how the shell is compared with the approved design by eye.
 */
function shellWorld(): DemoEnvironmentSeeder
{
    multiTenantDeployment();

    $seeder = new DemoEnvironmentSeeder;
    $seeder->setContainer(app());
    $seeder->run();
    // The console asks the native product catalog who may do what; the demo seeder does not
    // publish it.
    syncNativeProductCatalog();

    $environment = $seeder->environment;
    assert($environment !== null);

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    actAsEnvironmentAdmin($seeder->ownerId, $environment->id);
    confirmEnvironmentStepUp();
    app(Sudo::class)->confirm();

    return $seeder;
}

function shellShot(AwaitableWebpage|PendingAwaitablePage $page, string $name): void
{
    $page->wait(0.4);
    $page->script('document.fonts.ready.then(() => true)');
    $page->screenshot(false, 'shell-'.$name);
}

it('switches environment from the desktop header and stays on the page', function (string $theme): void {
    shellWorld();

    $pending = visit(envConsole('/users'));
    $page = ($theme === 'dark' ? $pending->inDarkMode() : $pending->inLightMode())->resize(1440, 900);

    $page->assertVisible('header.cbx-header')
        ->assertVisible('.cbx-rail')
        ->assertSee('Wile E. Coyote');

    shellShot($page, "desktop-users-{$theme}");

    $page->click('header.cbx-header button.cbx-trigger >> nth=-1')
        ->assertSee('Ledger Sandbox');

    shellShot($page, "desktop-context-{$theme}");

    $page->click('.cbx-popover [role="option"]:has-text("Ledger Sandbox")')
        ->wait(1)
        ->assertPathContains('/users')
        ->assertPathContains('ledger-sandbox')
        ->assertNoJavaScriptErrors();

    $page->click('button.cbx-search-button')->assertVisible('[role="dialog"] input');
    shellShot($page, "desktop-palette-{$theme}");
})->with(['light', 'dark'])->group('shell');

it('works the phone shell: tabs, More, the context sheet, the palette and "+"', function (string $theme): void {
    shellWorld();

    $pending = visit(envConsole('/users'));
    $page = ($theme === 'dark' ? $pending->inDarkMode() : $pending->inLightMode())->resize(390, 844);

    $page->assertVisible('nav.cbx-tabbar')
        ->assertVisible('button.cbx-m-pill')
        ->assertSee('Wile E. Coyote');

    shellShot($page, "phone-users-{$theme}");

    // More: the areas, Help, Switch app and the profile last.
    $page->click('nav.cbx-tabbar button[aria-controls="cbx-more"]')
        ->assertVisible('#cbx-more')
        ->assertSeeIn('#cbx-more', 'Switch app');

    shellShot($page, "phone-more-{$theme}");

    $page->navigate(envConsole('/users'))->assertVisible('button.cbx-m-pill');

    // The palette.
    $page->click('button[aria-label="Search and commands"]')
        ->assertSee('Cancel');

    shellShot($page, "phone-palette-{$theme}");

    $page->navigate(envConsole('/users'))->assertVisible('button.cbx-m-pill');

    // "+" on the Users page opens the Users page's own form.
    $page->click('button[aria-label="New user"]')
        ->assertSee('Email address');

    shellShot($page, "phone-create-{$theme}");

    $page->navigate(envConsole('/users'))->assertVisible('button.cbx-m-pill');

    // The context sheet, and a switch to the sandbox from it.
    $page->click('button.cbx-m-pill')
        ->assertSee('Ledger Sandbox');

    shellShot($page, "phone-context-{$theme}");

    $page->click('[role="dialog"] button:has-text("Ledger Sandbox")')
        ->wait(1)
        ->assertPathContains('ledger-sandbox')
        ->assertPathContains('/users')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark'])->group('shell');

/**
 * CBOX'S OWN ENVIRONMENT (cbox.cboxid.com): the account and organization pages every Cbox
 * user has. Not white-labelled, so the same shell as every other Cbox app, with the
 * organization the only level and kept in the session.
 */
it('draws Cbox\'s own environment console in the shared shell on a phone', function (string $theme): void {
    multiTenantDeployment();
    $cbox = provisionAccount('owner@cbox-phone.example');
    config(['cbox-id.cbox_environment' => $cbox['environment']->slug]);
    serveOnTestHost($cbox['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($cbox['environment']->id));
    syncNativeProductCatalog();
    actingAsRole(MembershipRole::Owner);

    $pending = visit('/account');
    $page = ($theme === 'dark' ? $pending->inDarkMode() : $pending->inLightMode())->resize(390, 844);

    $page->assertVisible('nav.cbx-tabbar')
        ->assertVisible('button.cbx-m-pill')
        ->assertNoJavaScriptErrors();

    shellShot($page, "cbox-account-{$theme}");

    $page->click('button.cbx-m-pill')->assertSee('Acme');

    shellShot($page, "cbox-context-{$theme}");
})->with(['light', 'dark'])->group('shell');
