<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

function polishConsole(): void
{
    // Run inside the test, after Pest has aligned app.url with its browser server.
    // A beforeEach fixture would claim the developer's or CI's .env host instead.
    installedDeployment();
    actAsEnvironmentAdminOfATenant();
    confirmEnvironmentStepUp();
}

it('edits a user in context and keeps the query, focus and deep link', function (): void {
    polishConsole();
    $user = app(Subjects::class)->create('dana@polish.test', 'Dana Example');
    $page = visit(envConsole('/users?q=dana'));
    $row = '[data-user-id="'.$user->id.'"]';
    $page->click($row)->assertVisible('[role="dialog"][data-state="open"]')
        ->assertScript('new URL(location.href).searchParams.get("selected")', $user->id)
        ->click('[aria-label="Edit Name"]')->fill('name', 'Dana Updated')
        ->click('button:has-text("Save")')
        ->assertSee('Profile updated.')
        ->assertVisible('[role="dialog"][data-state="open"]')
        ->assertSee('Dana Updated')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Edit Name')
        ->assertScript('new URL(location.href).searchParams.get("q")', 'dana');
    $page->click('[aria-label="Edit Name"]')->fill('name', 'Not saved');
    $page->script('window.confirm = () => false');
    $page->keys('input[name="name"]', 'Escape')->assertVisible('[role="dialog"][data-state="open"]')
        ->assertScript('document.querySelector("input[name=name]").value', 'Not saved')
        ->click('button:has-text("Cancel")')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Edit Name')
        ->assertSee('Dana Updated')
        ->assertNoJavaScriptErrors()
        ->wait(1)->assertNoAccessibilityIssues()
        ->screenshot(filename: 'polish-user-panel');
    // Let the original notification expire so Back cannot pass by retaining it.
    $page->wait(5)->assertDontSee('Profile updated.');
    $page->click('[aria-label="Close"]')->assertNotPresent('[role="dialog"][data-state="open"]')
        ->assertScript('new URL(location.href).searchParams.get("q")', 'dana')
        ->assertScript('document.activeElement?.getAttribute("data-user-id")', $user->id);
    $page->script('history.back()');
    $page->assertVisible('[role="dialog"][data-state="open"]')->assertSee('Dana Updated')
        ->assertDontSee('Profile updated.');
    $page->click('Open full profile')
        ->click('a[href$="section=security"]')->assertSee('Security & lifecycle')
        ->assertNoJavaScriptErrors();
});

it('fits the user panel on a phone and restores the URL selection on reload', function (bool $dark): void {
    polishConsole();
    $user = app(Subjects::class)->create('mobile@polish.test', 'Mobile Example');
    $page = visit(envConsole('/users?selected=').$user->id);
    if ($dark) {
        $page = $page->inDarkMode();
    }
    $page->resize(390, 844)->assertVisible('[role="dialog"][data-state="open"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Math.round(document.querySelector("[role=dialog][data-state=open]").getBoundingClientRect().width)', 390)
        ->assertNoAccessibilityIssues()->assertNoJavaScriptErrors()
        ->screenshot(filename: $dark ? 'polish-user-mobile-dark' : 'polish-user-mobile');
    $page->script('location.reload()');
    $page->assertVisible('[role="dialog"][data-state="open"]')->assertSee('Mobile Example');
    $page->click('[aria-label="Close"]')->assertNotPresent('[role="dialog"][data-state="open"]')
        ->assertScript('(() => {
            const row = document.querySelector("[data-user-id=\"'.$user->id.'\"]");
            const email = row.querySelector("span.text-sm");
            const statuses = row.querySelector(".cbx-user-row-status");
            return email.scrollWidth <= email.clientWidth
                && statuses.getBoundingClientRect().top >= email.getBoundingClientRect().bottom
                && statuses.firstElementChild.getBoundingClientRect().left === email.getBoundingClientRect().left;
        })()', true)
        ->screenshot(filename: $dark ? 'polish-user-list-mobile-dark' : 'polish-user-list-mobile');
})->with([false, true]);

it('reviews the portal task before creating a link and uses a sheet on a phone', function (): void {
    polishConsole();
    $org = app(Organizations::class)->create(new NewOrganization('Polish Company', 'polish-company'));
    grantFeature($org->id, 'cbox-id-sso');
    grantFeature($org->id, 'cbox-id-scim');
    $page = visit(envConsole('/organizations/').$org->id);
    $page->assertSee('No connection yet.')->screenshot(filename: 'polish-organization');
    $page->resize(390, 844);
    $page->click('Invite IT administrator')->assertVisible('[role="dialog"][data-state="open"]')
        ->assertDontSee('Link expires after')->click('button:has-text("Continue")')
        ->assertSee('Link expires after')->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues()->screenshot(filename: 'polish-portal-sheet');
    expect(AdminPortalLink::query()->count())->toBe(0);
    $page->click('button:has-text("Back")')->assertSee('What it opens')
        ->click('button:has-text("Continue")')->click('button:has-text("Create link")')
        ->assertSee('Setup link for their IT admin')->assertNoJavaScriptErrors();
    expect(AdminPortalLink::query()->count())->toBe(1);
});

it('reveals an invalid advanced app setting instead of leaving its error hidden', function (): void {
    polishConsole();
    $page = visit(envConsole('/apps/new'));
    $page->fill('name', 'Polished App')->fill('redirectUris', 'https://app.example/callback')
        ->click('summary:has-text("Sign-out URIs")')->fill('postLogoutRedirectUris', 'http://insecure.example/signed-out')
        ->click('summary:has-text("Sign-out URIs")')->click('button:has-text("Create app")')
        ->assertVisible('textarea[name="postLogoutRedirectUris"]')
        ->assertNoJavaScriptErrors();
});
