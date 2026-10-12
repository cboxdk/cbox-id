<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;

/*
|--------------------------------------------------------------------------
| THE PLATFORM'S OWN ENVIRONMENT CONSOLE, IN A BROWSER
|--------------------------------------------------------------------------
|
| `/admin` on the root host for a platform operator, the pages the root host answers a
| workspace owner with instead of an environment's settings, and the refusal at the end of
| a sign-in that worked — each in light, dark and on a phone, with no accessibility issue
| and no script error.
*/

beforeEach(function (): void {
    installedDeployment();

    // The browser's server answers on 127.0.0.1: make that the multi-tenant root's host.
    multiTenantDeployment('127.0.0.1');
});

/** An operator, signed in at the root with a confirmed address and a fresh step-up. */
function rootConsoleOperator(): void
{
    $operator = actAsOperator('browser-ops@platform.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, 'browser-ops@platform.test'));
    app(EnvironmentSudo::class)->confirm();
}

it('draws the platform environment\'s console for an operator without an accessibility issue', function (string $path, string $heading): void {
    provisionAccount('owner@acme.example');
    rootConsoleOperator();

    visit($path)
        ->assertSee($heading)
        ->assertSee('The platform’s own environment')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with([
    'overview' => ['/admin', 'Overview'],
    'social login' => ['/admin/social-sign-in', 'Social login'],
    'social login, adding Google' => ['/admin/social-sign-in?provider=google', 'Google'],
    'sign-in methods' => ['/admin/sign-in-methods', 'Sign-in methods'],
    'branding' => ['/admin/branding', 'Branding'],
])->group('a11y');

it('draws the platform environment\'s console in the dark and on a phone', function (): void {
    provisionAccount('owner@acme.example');
    rootConsoleOperator();

    visit('/admin/social-sign-in')->inDarkMode()
        ->assertSee('Social login')
        ->assertNoAccessibilityIssues();

    visit('/admin/social-sign-in')->resize(390, 844)
        ->assertSee('Social login')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('tells a workspace owner on the root where an environment\'s settings are, without an accessibility issue', function (): void {
    ['subjectId' => $owner] = provisionAccount('owner@acme.example');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($owner, 'owner@acme.example'));
    signInAsSubject($owner);

    visit('/social-sign-in')
        ->assertSee('is set for each environment')
        ->assertSee('Production')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    visit('/social-sign-in')->inDarkMode()->resize(390, 844)
        ->assertSee('is set for each environment')
        ->assertNoAccessibilityIssues();
})->group('a11y');

/*
 * PLATFORM ADMIN, AS AN AREA OF THIS CONSOLE. It was a console of its own at `/platform`,
 * with an amber strip and a rail of its own; it is the Platform area of `/admin` now, drawn
 * by the same shell — and the line above the page carries the warning the strip did.
 */
it('draws platform admin inside the platform environment\'s console, without an accessibility issue', function (string $path, string $heading): void {
    provisionAccount('owner@acme.example');
    rootConsoleOperator();

    visit($path)
        ->assertSee($heading)
        ->assertSee('Platform admin')
        ->assertSee('The whole deployment')
        // The environment's own areas are on the same rail.
        ->assertSee('Authentication')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    visit($path)->inDarkMode()
        ->assertSee($heading)
        ->assertNoAccessibilityIssues();

    visit($path)->resize(390, 844)
        ->assertSee($heading)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with([
    'workspaces' => ['/admin/platform/workspaces', 'Workspaces'],
    'environments' => ['/admin/platform/environments', 'Environments'],
    'organization tree' => ['/admin/platform/organizations', 'Organization tree'],
    'operators' => ['/admin/platform/operators', 'Operators'],
])->group('a11y');
