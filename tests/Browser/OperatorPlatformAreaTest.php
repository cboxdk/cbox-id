<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;

/*
|--------------------------------------------------------------------------
| THE WAY INTO PLATFORM ADMIN, IN A BROWSER
|--------------------------------------------------------------------------
|
| An operator signed in on the root host reaches platform admin and the platform's own
| environment from the rail on a desktop and from More on a phone, without typing `/admin`.
*/

beforeEach(function (): void {
    installedDeployment();

    // The browser's server answers on 127.0.0.1: make that the multi-tenant root's host.
    multiTenantDeployment('127.0.0.1');
});

function platformAreaOperator(): void
{
    $operator = actAsOperator('browser-ops@platform.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, 'browser-ops@platform.test'));
}

it('takes an operator from the rail to platform admin on a desktop', function (): void {
    provisionAccount('owner@acme.example');
    platformAreaOperator();

    visit('/settings')
        ->resize(1440, 900)
        ->assertVisible('nav.cbx-rail-nav a[href$="/admin/organizations"]')
        ->click('nav.cbx-rail-nav a[href$="/admin/organizations"]')
        ->assertPathIs('/admin/organizations')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('takes an operator from More to platform admin and the platform environment on a phone', function (): void {
    provisionAccount('owner@acme.example');
    platformAreaOperator();

    $page = visit('/settings')->resize(390, 844);

    $page->assertVisible('nav.cbx-tabbar')
        ->click('nav.cbx-tabbar button[aria-controls="cbx-more"]')
        ->assertVisible('#cbx-more')
        ->assertSeeIn('#cbx-more', 'Platform')
        ->click('#cbx-more :text-is("Platform")')
        ->assertSeeIn('#cbx-more', 'Organizations')
        ->assertSeeIn('#cbx-more', 'Platform environment')
        ->assertNoAccessibilityIssues()
        ->click('#cbx-more :text-is("Platform environment")')
        ->assertPathIs('/admin/environment')
        ->assertNoJavaScriptErrors();
})->group('a11y');
